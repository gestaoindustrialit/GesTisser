<?php
declare(strict_types=1);

/** Transactional production consumption ledger for labelled units and bulk stock. */
final class OperationConsumptionService
{
    private PDO $pdo;
    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    public function findUnit(string $type, string $label): ?array
    {
        [$table, $kind] = $this->unitDefinition($type);
        $stmt = $this->pdo->prepare('SELECT l.*,rm.code article_code,rm.description,rm.width,rm.grammage,rm.average_price,u.code unit_code,s.code supplier_code,w.code warehouse_code,loc.code location_code FROM '.$table.' l JOIN erp_raw_materials rm ON rm.id=l.raw_material_id LEFT JOIN erp_units u ON u.id=rm.primary_unit_id LEFT JOIN erp_suppliers s ON s.id=rm.preferred_supplier_id LEFT JOIN erp_warehouses w ON w.id=l.warehouse_id LEFT JOIN erp_locations loc ON loc.id=l.location_id WHERE l.barcode=? LIMIT 1');
        $stmt->execute([trim($label)]); $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $row['unit_type'] = $kind;
        $row['current_quantity'] = (float) $row['weight_kg'];
        $row['initial_quantity'] = (float) ($row['initial_weight_kg'] ?? $row['weight_kg']);
        return $row;
    }

    public function startUnit(int $operationId, string $type, string $label, int $userId, int $workstationId, string $key): int
    {
        $key = $this->key($key); $this->pdo->beginTransaction();
        try {
            if ($id = $this->existingKey($key)) { $this->pdo->commit(); return $id; }
            $operation = $this->operation($operationId);
            $unit = $this->findUnit($type, $label);
            if (!$unit) throw new InvalidArgumentException('Etiqueta não encontrada.');
            if ((float)$unit['weight_kg'] <= 0 || in_array((string)$unit['status'], ['CONSUMED','BLOCKED'], true)) throw new InvalidArgumentException('Esta unidade não está disponível para consumo.');
            [$table, $kind] = $this->unitDefinition($type);
            $active = $this->pdo->prepare('SELECT 1 FROM erp_production_consumptions WHERE stock_unit_type=? AND stock_unit_id=? AND completed_at IS NULL LIMIT 1');
            $active->execute([$kind,(int)$unit['id']]);
            if ($active->fetchColumn()) throw new InvalidArgumentException('Esta unidade já está em utilização noutra operação.');
            $changed = $this->pdo->prepare('UPDATE '.$table.' SET status="IN_USE",updated_at=CURRENT_TIMESTAMP WHERE id=? AND status NOT IN ("CONSUMED","BLOCKED","IN_USE")');
            $changed->execute([(int)$unit['id']]);
            if (!$changed->rowCount()) throw new RuntimeException('A unidade deixou de estar disponível. Atualize e tente novamente.');
            $insert = $this->pdo->prepare('INSERT INTO erp_production_consumptions(production_order_id,production_order_operation_id,product_id,raw_material_id,quantity,unit_cost,created_by,consumption_type,stock_unit_type,stock_unit_id,quantity_before,unit_code,workstation_id,idempotency_key) VALUES (?,?,?,?,0,?,?,?,?,?,?,?, ?,?)');
            $insert->execute([(int)$operation['production_order_id'],$operationId,(int)$operation['product_id'],(int)$unit['raw_material_id'],(float)$unit['average_price'],$userId,$kind,$kind,(int)$unit['id'],(float)$unit['weight_kg'],(string)($unit['unit_code'] ?: 'kg'),$workstationId ?: null,$key]);
            $id=(int)$this->pdo->lastInsertId(); $this->pdo->commit(); return $id;
        } catch (Throwable $e) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
    }

    public function finishUnit(int $consumptionId, float $remaining, int $userId, string $key): array
    {
        $key=$this->key($key); $this->pdo->beginTransaction();
        try {
            if ($id=$this->existingKey($key)) { $row=$this->consumption($id); $this->pdo->commit(); return $row; }
            $row=$this->consumption($consumptionId);
            if (!in_array((string)$row['stock_unit_type'],['ROLL','INK'],true) || $row['completed_at']!==null) throw new InvalidArgumentException('A utilização selecionada já foi encerrada ou não é válida.');
            $before=(float)$row['quantity_before'];
            if ($remaining < 0 || $remaining > $before) throw new InvalidArgumentException('A quantidade restante deve estar entre zero e a quantidade inicial.');
            $quantity=$before-$remaining;
            if ($quantity <= 0) throw new InvalidArgumentException('A quantidade restante tem de ser inferior à quantidade inicial.');
            [$table]=$this->unitDefinition((string)$row['stock_unit_type']);
            $current=$this->pdo->prepare('SELECT weight_kg,status FROM '.$table.' WHERE id=?'); $current->execute([(int)$row['stock_unit_id']]); $unit=$current->fetch(PDO::FETCH_ASSOC);
            if (!$unit || (string)$unit['status']!=='IN_USE' || abs((float)$unit['weight_kg']-$before)>0.00001) throw new RuntimeException('A quantidade/estado da unidade mudou. Atualize e tente novamente.');
            $movementId=$this->deductStock((int)$row['raw_material_id'],$quantity,(float)$row['unit_cost'],$userId,(int)$row['production_order_id'],(int)$row['production_order_operation_id'],(int)$row['stock_unit_id'],(string)$row['stock_unit_type']);
            $status=$remaining==0.0?'CONSUMED':'PARTIAL';
            $metresSql=(string)$row['stock_unit_type']==='ROLL'?',metres=CASE WHEN weight_kg>0 AND metres>=0 THEN metres*?/weight_kg ELSE metres END':'';
            $params=$metresSql!==''?[$remaining,$remaining,$status,(int)$row['stock_unit_id']]:[$remaining,$status,(int)$row['stock_unit_id']];
            $this->pdo->prepare('UPDATE '.$table.' SET weight_kg=?'.$metresSql.',status=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status="IN_USE"')->execute($params);
            $this->pdo->prepare('UPDATE erp_production_consumptions SET quantity=?,quantity_after=?,source_movement_id=?,completed_at=CURRENT_TIMESTAMP,idempotency_key=? WHERE id=? AND completed_at IS NULL')->execute([$quantity,$remaining,$movementId,$key,$consumptionId]);
            $this->pdo->commit(); return $this->consumption($consumptionId);
        } catch(Throwable $e) { if($this->pdo->inTransaction())$this->pdo->rollBack(); throw $e; }
    }

    public function direct(int $operationId, int $materialId, float $quantity, int $userId, int $workstationId, string $key): int
    {
        if($quantity<=0) throw new InvalidArgumentException('A quantidade deve ser superior a zero.');
        $key=$this->key($key);$this->pdo->beginTransaction();
        try { if($id=$this->existingKey($key)){$this->pdo->commit();return $id;}$op=$this->operation($operationId);
            $m=$this->pdo->prepare('SELECT rm.*,u.code unit_code FROM erp_raw_materials rm LEFT JOIN erp_units u ON u.id=rm.primary_unit_id WHERE rm.id=? AND rm.status="Ativo"');$m->execute([$materialId]);$material=$m->fetch(PDO::FETCH_ASSOC);if(!$material)throw new InvalidArgumentException('Material inválido ou inativo.');
            $insert=$this->pdo->prepare('INSERT INTO erp_production_consumptions(production_order_id,production_order_operation_id,product_id,raw_material_id,quantity,unit_cost,created_by,consumption_type,quantity_before,quantity_after,unit_code,workstation_id,idempotency_key,completed_at) VALUES (?,?,?,?,?,?,?,?,?,?,?, ?,?,CURRENT_TIMESTAMP)');
            $insert->execute([(int)$op['production_order_id'],$operationId,(int)$op['product_id'],$materialId,$quantity,(float)$material['average_price'],$userId,'DIRECT',null,null,(string)($material['unit_code']?:'kg'),$workstationId?:null,$key]);$id=(int)$this->pdo->lastInsertId();
            $movement=$this->deductStock($materialId,$quantity,(float)$material['average_price'],$userId,(int)$op['production_order_id'],$operationId,null,'DIRECT');
            $this->pdo->prepare('UPDATE erp_production_consumptions SET source_movement_id=? WHERE id=?')->execute([$movement,$id]);$this->pdo->commit();return $id;
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    private function deductStock(int $materialId,float $quantity,float $cost,int $userId,int $orderId,int $operationId,?int $unitId,string $kind): int
    {
        $rows=$this->pdo->prepare('SELECT rowid,warehouse_id,location_id,lot,physical_qty,reserved_qty,blocked_qty FROM erp_stock_balances WHERE item_type="raw_material" AND item_id=? AND physical_qty>blocked_qty ORDER BY CASE WHEN reserved_qty>0 THEN 0 ELSE 1 END,updated_at,rowid');$rows->execute([$materialId]);$remaining=$quantity;$parts=[];
        foreach($rows->fetchAll(PDO::FETCH_ASSOC) as $row){$usable=max(0,(float)$row['physical_qty']-(float)$row['blocked_qty']);$take=min($remaining,$usable);if($take<=0)continue;$release=min($take,(float)$row['reserved_qty']);$update=$this->pdo->prepare('UPDATE erp_stock_balances SET physical_qty=physical_qty-CAST(? AS REAL),reserved_qty=MAX(0,reserved_qty-CAST(? AS REAL)),updated_at=CURRENT_TIMESTAMP WHERE rowid=CAST(? AS INTEGER) AND physical_qty-blocked_qty>=CAST(? AS REAL)');$update->execute([$take,$release,(int)$row['rowid'],$take]);if(!$update->rowCount())throw new RuntimeException('O stock foi alterado por outro utilizador. Atualize e tente novamente.');$parts[]=[$row,$take];$remaining-=$take;if($remaining<0.000001)break;}
        if($remaining>0.000001)throw new RuntimeException('Stock disponível insuficiente. Disponível: '.number_format($quantity-$remaining,3,',','.').'.');
        $number='CONS-'.date('YmdHis').'-'.bin2hex(random_bytes(4));$first=$parts[0][0];
        $notes='Consumo '.$kind.' · OF '.$orderId.' · operação '.$operationId.($unitId?' · unidade '.$unitId:'');
        $stmt=$this->pdo->prepare('INSERT INTO erp_stock_movements(movement_number,movement_type,item_type,item_id,lot,roll_id,quantity,weight,warehouse_from_id,location_from_id,unit_cost,total_cost,source_type,source_id,order_reference,reason,notes,created_by) VALUES (?,"Consumo produção","raw_material",?,?,?,?,?,?,?,?,?,"production_operation",?,?,"PRODUCTION_CONSUMPTION",?,?)');
        $stmt->execute([$number,$materialId,(string)$first['lot'],$unitId,$quantity,$quantity,$first['warehouse_id'],$first['location_id'],$cost,$quantity*$cost,$operationId,(string)$orderId,$notes,$userId]);return(int)$this->pdo->lastInsertId();
    }
    private function operation(int $id): array {$s=$this->pdo->prepare('SELECT opo.production_order_id,po.product_id FROM erp_production_order_operations opo JOIN erp_production_orders po ON po.id=opo.production_order_id WHERE opo.id=?');$s->execute([$id]);$r=$s->fetch(PDO::FETCH_ASSOC);if(!$r)throw new InvalidArgumentException('Operação inválida.');return$r;}
    private function consumption(int $id): array {$s=$this->pdo->prepare('SELECT * FROM erp_production_consumptions WHERE id=?');$s->execute([$id]);$r=$s->fetch(PDO::FETCH_ASSOC);if(!$r)throw new InvalidArgumentException('Consumo inválido.');return$r;}
    private function existingKey(string $key): int {$s=$this->pdo->prepare('SELECT id FROM erp_production_consumptions WHERE idempotency_key=?');$s->execute([$key]);return(int)($s->fetchColumn()?:0);}
    private function key(string $key): string {$key=trim($key);if($key==='')throw new InvalidArgumentException('Pedido sem chave de segurança.');return substr($key,0,100);}
    private function unitDefinition(string $type): array {$type=strtoupper(trim($type));if($type==='ROLL')return['erp_raw_material_roll_labels','ROLL'];if($type==='INK')return['erp_raw_material_ink_labels','INK'];throw new InvalidArgumentException('Tipo de unidade inválido.');}
}
