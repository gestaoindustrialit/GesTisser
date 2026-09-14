<?php
declare(strict_types=1);

final class PurchaseReceiptService
{
    public static function receive(PDO $pdo, array $data, int $userId): array
    {
        $orderId = (int) ($data['purchase_order_id'] ?? 0);
        $warehouseId = (int) ($data['warehouse_id'] ?? 0);
        $locationId = (int) ($data['location_id'] ?? 0);
        $lineIds = (array) ($data['receipt_line_id'] ?? []);
        $quantities = (array) ($data['receipt_quantity'] ?? []);
        $labels = (array) ($data['receipt_labels'] ?? []);
        $costs = (array) ($data['receipt_unit_cost'] ?? []);

        $orderStmt = $pdo->prepare('SELECT * FROM erp_purchase_orders WHERE id=? AND status IN ("Aberta","Parcial")');
        $orderStmt->execute([$orderId]);
        $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) { throw new RuntimeException('Selecione uma encomenda aberta.'); }

        $lineStmt = $pdo->prepare('SELECT pol.*, COALESCE((SELECT SUM(sm.quantity) FROM erp_stock_movements sm WHERE sm.purchase_order_line_id=pol.id AND sm.movement_type="Entrada"),0) received_quantity FROM erp_purchase_order_lines pol WHERE pol.id=? AND pol.purchase_order_id=?');
        $insertMovement = $pdo->prepare('INSERT INTO erp_stock_movements(movement_number,movement_type,item_type,item_id,quantity,warehouse_to_id,location_to_id,unit_cost,total_cost,source_type,source_id,order_reference,purchase_order_line_id,labels_to_print,reason,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $updateBalance = $pdo->prepare('UPDATE erp_stock_balances SET physical_qty=physical_qty+?,updated_at=CURRENT_TIMESTAMP WHERE item_type=? AND item_id=CAST(? AS INTEGER) AND COALESCE(warehouse_id,0)=CAST(? AS INTEGER) AND COALESCE(location_id,0)=CAST(? AS INTEGER) AND COALESCE(lot,"")=""');
        $insertBalance = $pdo->prepare('INSERT INTO erp_stock_balances(item_type,item_id,warehouse_id,location_id,lot,physical_qty) VALUES (?,?,?,? ,"",?)');
        $movementIds = [];
        $seen = [];

        foreach ($lineIds as $index => $rawLineId) {
            $lineId = (int) $rawLineId;
            $quantity = (float) ($quantities[$index] ?? 0);
            if ($quantity <= 0) { continue; }
            if (isset($seen[$lineId])) { throw new RuntimeException('Uma linha da encomenda foi repetida na receção.'); }
            $seen[$lineId] = true;
            $lineStmt->execute([$lineId, $orderId]);
            $line = $lineStmt->fetch(PDO::FETCH_ASSOC);
            if (!$line) { throw new RuntimeException('Um dos artigos não pertence à encomenda selecionada.'); }
            $missing = max(0.0, (float) $line['ordered_quantity'] - (float) $line['received_quantity']);
            if ($quantity > $missing + 0.000001) { throw new RuntimeException('A quantidade recebida excede a quantidade em falta da linha '.(int) $line['line_no'].'.'); }
            $labelCount = (int) ($labels[$index] ?? 0);
            if ($labelCount < 0 || $labelCount > 1000) { throw new RuntimeException('O número de etiquetas deve estar entre 0 e 1000.'); }
            $unitCost = max(0.0, (float) ($costs[$index] ?? $line['unit_cost']));
            $number = 'MOV-'.date('YmdHis').'-'.$userId.'-'.($index + 1).'-'.bin2hex(random_bytes(2));
            $insertMovement->execute([$number,'Entrada',$line['item_type'],(int) $line['item_id'],$quantity,$warehouseId ?: null,$locationId ?: null,$unitCost,$quantity*$unitCost,'purchase_order',$orderId,$order['order_number'],$lineId,$labelCount,trim((string) ($data['reason'] ?? '')),trim((string) ($data['notes'] ?? '')),$userId]);
            $movementIds[] = (int) $pdo->lastInsertId();
            $updateBalance->execute([$quantity,$line['item_type'],(int) $line['item_id'],$warehouseId,$locationId]);
            if ($updateBalance->rowCount() === 0) {
                $insertBalance->execute([$line['item_type'],(int) $line['item_id'],$warehouseId ?: null,$locationId ?: null,$quantity]);
            }
        }
        if (!$movementIds) { throw new RuntimeException('Indique uma quantidade a receber em pelo menos uma linha.'); }

        $remainingStmt = $pdo->prepare('SELECT COUNT(*) FROM erp_purchase_order_lines pol WHERE pol.purchase_order_id=? AND pol.ordered_quantity > COALESCE((SELECT SUM(sm.quantity) FROM erp_stock_movements sm WHERE sm.purchase_order_line_id=pol.id AND sm.movement_type="Entrada"),0)+0.000001');
        $remainingStmt->execute([$orderId]);
        $status = (int) $remainingStmt->fetchColumn() === 0 ? 'Recebida' : 'Parcial';
        $pdo->prepare('UPDATE erp_purchase_orders SET status=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$status,$orderId]);
        return ['movement_ids'=>$movementIds,'status'=>$status];
    }
}
