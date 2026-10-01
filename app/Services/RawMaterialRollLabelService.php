<?php
declare(strict_types=1);

final class RawMaterialRollLabelService
{
    private $pdo;
    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    public function material(int $id): array
    {
        $stmt=$this->pdo->prepare('SELECT id,code,description,roll_controlled FROM erp_raw_materials WHERE id=?');
        $stmt->execute([$id]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new RuntimeException('A matéria-prima selecionada não existe.');
        return $row;
    }

    public function find(int $id)
    {
        $stmt=$this->pdo->prepare('SELECT l.*,rm.code article_code,rm.description,u.name validator_name FROM erp_raw_material_roll_labels l JOIN erp_raw_materials rm ON rm.id=l.raw_material_id LEFT JOIN users u ON u.id=l.validated_by WHERE l.id=?');
        $stmt->execute([$id]);return $stmt->fetch(PDO::FETCH_ASSOC)?:null;
    }

    public function listForMaterial(int $materialId): array
    {
        $stmt=$this->pdo->prepare('SELECT id,entry_number,supplier_lot,barcode,label_date FROM erp_raw_material_roll_labels WHERE raw_material_id=? ORDER BY label_date DESC,id DESC');
        $stmt->execute([$materialId]);return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function search(int $materialId,string $term=''): array
    {
        $sql='SELECT id,entry_number,supplier_lot,metres,weight_kg,barcode,label_date FROM erp_raw_material_roll_labels l WHERE raw_material_id=? AND NOT EXISTS (SELECT 1 FROM erp_raw_material_roll_consumptions c WHERE c.source_label_id=l.id)';$params=[$materialId];$term=trim($term);
        if($term!==''){$sql.=' AND (entry_number LIKE ? OR supplier_lot LIKE ? OR barcode LIKE ?)';$like='%'.$term.'%';$params[]=$like;$params[]=$like;$params[]=$like;}
        $sql.=' ORDER BY label_date DESC,id DESC LIMIT 50';$stmt=$this->pdo->prepare($sql);$stmt->execute($params);return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function productionOrders(): array
    {
        $stmt=$this->pdo->query('SELECT id,order_number,status FROM erp_production_orders WHERE status NOT IN ("Concluída","Encerrada","Fechada","Cancelada") ORDER BY CASE WHEN status IN ("Por iniciar","Em Produção","Planeada","Em curso") THEN 0 ELSE 1 END,due_date,id DESC');return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function save(int $materialId,array $values,int $userId,int $id=0): array
    {
        $this->material($materialId);
        $entry=trim((string)($values['entry_number']??''));$lot=trim((string)($values['supplier_lot']??''));$barcode=trim((string)($values['barcode']??''));$date=trim((string)($values['label_date']??''));
        $metres=(float)($values['metres']??0);$weight=(float)($values['weight_kg']??0);
        if($entry===''||$lot===''||$barcode===''||$date==='')throw new InvalidArgumentException('Preencha a entrada, o lote do fornecedor, o código de barras e a data.');
        if($metres<=0||$weight<=0)throw new InvalidArgumentException('Os metros e o peso devem ser superiores a zero.');
        $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);if(!$parsed||$parsed->format('Y-m-d')!==$date)throw new InvalidArgumentException('A data da etiqueta não é válida.');
        if($id>0){$current=$this->find($id);if(!$current||(int)$current['raw_material_id']!==$materialId)throw new RuntimeException('A etiqueta selecionada não pertence a esta matéria-prima.');$stmt=$this->pdo->prepare('UPDATE erp_raw_material_roll_labels SET entry_number=?,supplier_lot=?,metres=?,weight_kg=?,barcode=?,label_date=?,validated_by=?,validated_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=?');$stmt->execute([$entry,$lot,$metres,$weight,$barcode,$date,$userId,$id]);}
        else{$stmt=$this->pdo->prepare('INSERT INTO erp_raw_material_roll_labels(raw_material_id,entry_number,supplier_lot,metres,weight_kg,barcode,label_date,validated_by) VALUES (?,?,?,?,?,?,?,?)');$stmt->execute([$materialId,$entry,$lot,$metres,$weight,$barcode,$date,$userId]);$id=(int)$this->pdo->lastInsertId();if($this->hasColumn('initial_metres'))$this->pdo->prepare('UPDATE erp_raw_material_roll_labels SET initial_metres=?,initial_weight_kg=?,status="AVAILABLE" WHERE id=?')->execute([$metres,$weight,$id]);}
        return $this->find($id)?:[];
    }

    private function hasColumn(string $column): bool
    { foreach($this->pdo->query('PRAGMA table_info(erp_raw_material_roll_labels)')->fetchAll(PDO::FETCH_ASSOC) as $row)if((string)$row['name']===$column)return true;return false; }

    public function relabel(int $sourceId,int $orderId,array $values,int $userId): array
    {
        $source=$this->find($sourceId);if(!$source)throw new RuntimeException('O rolo selecionado não existe.');if($orderId<1)throw new InvalidArgumentException('Selecione a OF onde a matéria-prima foi consumida.');
        $check=$this->pdo->prepare('SELECT 1 FROM erp_production_orders WHERE id=?');$check->execute([$orderId]);if(!$check->fetchColumn())throw new InvalidArgumentException('A OF selecionada não existe.');
        $remainingMetres=(float)($values['metres']??0);$remainingWeight=(float)($values['weight_kg']??0);if($remainingMetres>(float)$source['metres']||$remainingWeight>(float)$source['weight_kg'])throw new InvalidArgumentException('A quantidade restante não pode exceder a quantidade do rolo selecionado.');
        $consumedMetres=(float)$source['metres']-$remainingMetres;$consumedWeight=(float)$source['weight_kg']-$remainingWeight;if($consumedMetres<=0&&$consumedWeight<=0)throw new InvalidArgumentException('Reduza os metros ou o peso para registar o consumo.');
        $ownsTransaction=!$this->pdo->inTransaction();if($ownsTransaction)$this->pdo->beginTransaction();
        try{$used=$this->pdo->prepare('SELECT 1 FROM erp_raw_material_roll_consumptions WHERE source_label_id=?');$used->execute([$sourceId]);if($used->fetchColumn())throw new RuntimeException('Este rolo já foi consumido e substituído por uma nova etiqueta.');$result=$this->save((int)$source['raw_material_id'],$values,$userId);$stmt=$this->pdo->prepare('INSERT INTO erp_raw_material_roll_consumptions(source_label_id,resulting_label_id,production_order_id,consumed_metres,consumed_weight_kg,created_by) VALUES (?,?,?,?,?,?)');$stmt->execute([$sourceId,(int)$result['id'],$orderId,$consumedMetres,$consumedWeight,$userId]);if($ownsTransaction)$this->pdo->commit();return $result;}catch(Throwable $e){if($ownsTransaction&&$this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
}
