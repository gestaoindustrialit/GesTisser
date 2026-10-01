<?php
declare(strict_types=1);

final class RawMaterialInkLabelService
{
    private $pdo;
    public function __construct(PDO $pdo) { $this->pdo=$pdo; }
    public function material(int $id): array
    {
        $stmt=$this->pdo->prepare('SELECT rm.id,rm.code,rm.description,rm.product_category,it.name ink_type_name FROM erp_raw_materials rm LEFT JOIN erp_ink_types it ON it.id=rm.ink_type_id WHERE rm.id=?');$stmt->execute([$id]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new RuntimeException('A tinta selecionada não existe.');return $row;
    }
    public function find(int $id)
    {
        $stmt=$this->pdo->prepare('SELECT l.*,rm.code article_code,rm.description,it.name ink_type_name,u.name validator_name FROM erp_raw_material_ink_labels l JOIN erp_raw_materials rm ON rm.id=l.raw_material_id LEFT JOIN erp_ink_types it ON it.id=rm.ink_type_id LEFT JOIN users u ON u.id=l.validated_by WHERE l.id=?');$stmt->execute([$id]);return $stmt->fetch(PDO::FETCH_ASSOC)?:null;
    }
    public function listForMaterial(int $materialId): array
    {
        $stmt=$this->pdo->prepare('SELECT id,entry_number,supplier_lot,barcode,label_date FROM erp_raw_material_ink_labels WHERE raw_material_id=? ORDER BY label_date DESC,id DESC');$stmt->execute([$materialId]);return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    public function save(int $materialId,array $values,int $userId,int $id=0): array
    {
        $this->material($materialId);$entry=trim((string)($values['entry_number']??''));$lot=trim((string)($values['supplier_lot']??''));$barcode=trim((string)($values['barcode']??''));$date=trim((string)($values['label_date']??''));$weight=(float)($values['weight_kg']??0);
        if($entry===''||$lot===''||$barcode===''||$date==='')throw new InvalidArgumentException('Preencha a entrada, o lote do fornecedor, o código de barras e a data.');if($weight<=0)throw new InvalidArgumentException('O peso deve ser superior a zero.');
        $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);if(!$parsed||$parsed->format('Y-m-d')!==$date)throw new InvalidArgumentException('A data da etiqueta não é válida.');
        if($id>0){$current=$this->find($id);if(!$current||(int)$current['raw_material_id']!==$materialId)throw new RuntimeException('A etiqueta selecionada não pertence a esta tinta.');$stmt=$this->pdo->prepare('UPDATE erp_raw_material_ink_labels SET entry_number=?,supplier_lot=?,weight_kg=?,barcode=?,label_date=?,validated_by=?,validated_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=?');$stmt->execute([$entry,$lot,$weight,$barcode,$date,$userId,$id]);}
        else{$stmt=$this->pdo->prepare('INSERT INTO erp_raw_material_ink_labels(raw_material_id,entry_number,supplier_lot,weight_kg,barcode,label_date,validated_by) VALUES (?,?,?,?,?,?,?)');$stmt->execute([$materialId,$entry,$lot,$weight,$barcode,$date,$userId]);$id=(int)$this->pdo->lastInsertId();if($this->hasColumn('initial_weight_kg'))$this->pdo->prepare('UPDATE erp_raw_material_ink_labels SET initial_weight_kg=?,status="AVAILABLE" WHERE id=?')->execute([$weight,$id]);}return $this->find($id)?:[];
    }
    private function hasColumn(string $column): bool
    { foreach($this->pdo->query('PRAGMA table_info(erp_raw_material_ink_labels)')->fetchAll(PDO::FETCH_ASSOC) as $row)if((string)$row['name']===$column)return true;return false; }
}
