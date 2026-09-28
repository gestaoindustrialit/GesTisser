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

    public function save(int $materialId,array $values,int $userId,int $id=0): array
    {
        $this->material($materialId);
        $entry=trim((string)($values['entry_number']??''));$lot=trim((string)($values['supplier_lot']??''));$barcode=trim((string)($values['barcode']??''));$date=trim((string)($values['label_date']??''));
        $metres=(float)($values['metres']??0);$weight=(float)($values['weight_kg']??0);
        if($entry===''||$lot===''||$barcode===''||$date==='')throw new InvalidArgumentException('Preencha a entrada, o lote do fornecedor, o código de barras e a data.');
        if($metres<=0||$weight<=0)throw new InvalidArgumentException('Os metros e o peso devem ser superiores a zero.');
        $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);if(!$parsed||$parsed->format('Y-m-d')!==$date)throw new InvalidArgumentException('A data da etiqueta não é válida.');
        if($id>0){$current=$this->find($id);if(!$current||(int)$current['raw_material_id']!==$materialId)throw new RuntimeException('A etiqueta selecionada não pertence a esta matéria-prima.');$stmt=$this->pdo->prepare('UPDATE erp_raw_material_roll_labels SET entry_number=?,supplier_lot=?,metres=?,weight_kg=?,barcode=?,label_date=?,validated_by=?,validated_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=?');$stmt->execute([$entry,$lot,$metres,$weight,$barcode,$date,$userId,$id]);}
        else{$stmt=$this->pdo->prepare('INSERT INTO erp_raw_material_roll_labels(raw_material_id,entry_number,supplier_lot,metres,weight_kg,barcode,label_date,validated_by) VALUES (?,?,?,?,?,?,?,?)');$stmt->execute([$materialId,$entry,$lot,$metres,$weight,$barcode,$date,$userId]);$id=(int)$this->pdo->lastInsertId();}
        return $this->find($id)?:[];
    }
}
