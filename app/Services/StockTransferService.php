<?php
declare(strict_types=1);

/** Applies an inventory transfer and records it in the stock ledger. */
final class StockTransferService
{
    public static function transfer(PDO $pdo, array $input, int $userId): array
    {
        $itemType = (string) ($input['item_type'] ?? '');
        $itemId = (int) ($input['item_id'] ?? 0);
        $quantity = (float) ($input['quantity'] ?? 0);
        $lot = trim((string) ($input['lot'] ?? ''));
        $warehouseFrom = (int) ($input['warehouse_from_id'] ?? 0);
        $locationFrom = (int) ($input['location_from_id'] ?? 0);
        $warehouseTo = (int) ($input['warehouse_to_id'] ?? 0);
        $locationTo = (int) ($input['location_to_id'] ?? 0);

        $itemTable = $itemType === 'raw_material' ? 'erp_raw_materials' : ($itemType === 'finished_product' ? 'erp_finished_products' : '');
        if ($itemTable === '' || $itemId < 1) throw new RuntimeException('Selecione um artigo válido para a movimentação.');
        if ($quantity <= 0) throw new RuntimeException('A quantidade a movimentar deve ser superior a zero.');
        if (!$warehouseFrom || !$warehouseTo) throw new RuntimeException('Selecione os armazéns de origem e destino.');
        if ($warehouseFrom === $warehouseTo && $locationFrom === $locationTo) throw new RuntimeException('A origem e o destino da movimentação têm de ser diferentes.');

        $query = $pdo->prepare('SELECT 1 FROM '.$itemTable.' WHERE id=?');
        $query->execute([$itemId]);
        if (!$query->fetchColumn()) throw new RuntimeException('O artigo selecionado não existe.');

        foreach ([[$warehouseFrom, $locationFrom, 'origem'], [$warehouseTo, $locationTo, 'destino']] as $place) {
            $query = $pdo->prepare('SELECT 1 FROM erp_warehouses WHERE id=? AND is_active=1');
            $query->execute([$place[0]]);
            if (!$query->fetchColumn()) throw new RuntimeException('O armazém de '.$place[2].' não é válido ou está inativo.');
            if ($place[1]) {
                $query = $pdo->prepare('SELECT 1 FROM erp_locations WHERE id=? AND warehouse_id=? AND is_active=1');
                $query->execute([$place[1], $place[0]]);
                if (!$query->fetchColumn()) throw new RuntimeException('A posição de '.$place[2].' não pertence ao armazém selecionado ou está inativa.');
            }
        }

        // PDO supplies execute-array values as strings. CAST is necessary here because
        // the COALESCE expression has no SQLite type affinity ("10" would not match 10).
        $findBalance = $pdo->prepare('SELECT physical_qty,reserved_qty,blocked_qty FROM erp_stock_balances WHERE item_type=? AND item_id=? AND warehouse_id=? AND COALESCE(location_id,0)=CAST(? AS INTEGER) AND COALESCE(lot,"")=?');
        $findBalance->execute([$itemType, $itemId, $warehouseFrom, $locationFrom, $lot]);
        $source = $findBalance->fetch(PDO::FETCH_ASSOC);
        $available = (float) ($source['physical_qty'] ?? 0) - (float) ($source['reserved_qty'] ?? 0) - (float) ($source['blocked_qty'] ?? 0);
        if (!$source || $available < $quantity) throw new RuntimeException('Stock disponível insuficiente na origem para esta movimentação. Disponível: '.number_format(max(0, $available), 3, ',', '.').'.');

        $update = $pdo->prepare('UPDATE erp_stock_balances SET physical_qty=physical_qty-?,updated_at=CURRENT_TIMESTAMP WHERE item_type=? AND item_id=? AND warehouse_id=? AND COALESCE(location_id,0)=CAST(? AS INTEGER) AND COALESCE(lot,"")=?');
        $update->execute([$quantity, $itemType, $itemId, $warehouseFrom, $locationFrom, $lot]);

        $findBalance->execute([$itemType, $itemId, $warehouseTo, $locationTo, $lot]);
        if ($findBalance->fetch(PDO::FETCH_ASSOC)) {
            $update = $pdo->prepare('UPDATE erp_stock_balances SET physical_qty=physical_qty+?,updated_at=CURRENT_TIMESTAMP WHERE item_type=? AND item_id=? AND warehouse_id=? AND COALESCE(location_id,0)=CAST(? AS INTEGER) AND COALESCE(lot,"")=?');
            $update->execute([$quantity, $itemType, $itemId, $warehouseTo, $locationTo, $lot]);
        } else {
            $pdo->prepare('INSERT INTO erp_stock_balances(item_type,item_id,warehouse_id,location_id,lot,physical_qty) VALUES (?,?,?,?,?,?)')->execute([$itemType, $itemId, $warehouseTo, $locationTo ?: null, $lot, $quantity]);
        }

        // Including microseconds and random bytes avoids collisions when a user submits
        // several movements within the same second.
        $movementNumber = 'MOV-'.date('YmdHis').'-'.sprintf('%06d', (int) ((microtime(true) * 1000000) % 1000000)).'-'.bin2hex(random_bytes(3));
        $unitCost = max(0, (float) ($input['unit_cost'] ?? 0));
        $reason = trim((string) ($input['reason'] ?? ''));
        $notes = trim((string) ($input['notes'] ?? ''));
        $pdo->prepare('INSERT INTO erp_stock_movements(movement_number,movement_type,item_type,item_id,lot,quantity,warehouse_from_id,location_from_id,warehouse_to_id,location_to_id,unit_cost,total_cost,reason,notes,created_by) VALUES (?,"Transferência",?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$movementNumber, $itemType, $itemId, $lot, $quantity, $warehouseFrom, $locationFrom ?: null, $warehouseTo, $locationTo ?: null, $unitCost, $quantity * $unitCost, $reason, $notes, $userId]);

        return ['id' => (int) $pdo->lastInsertId(), 'movement_number' => $movementNumber, 'quantity' => $quantity, 'warehouse_from_id' => $warehouseFrom, 'warehouse_to_id' => $warehouseTo, 'reason' => $reason];
    }
}
