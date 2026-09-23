<?php
declare(strict_types=1);

final class ProductionLabelService
{
    private $pdo;

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    public function find(int $orderId, string $type, string $reference): ?array
    {
        $stmt=$this->pdo->prepare('SELECT l.*,u.name validator_name FROM erp_production_labels l LEFT JOIN users u ON u.id=l.validated_by WHERE l.production_order_id=? AND l.label_type=? AND l.reference_key=?');
        $stmt->execute([$orderId,$type,$reference]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$row)return null;$row['values']=json_decode((string)$row['values_json'],true)?:[];return $row;
    }

    public function listForOrder(int $orderId,string $type): array
    {
        $stmt=$this->pdo->prepare('SELECT reference_key,validated_at FROM erp_production_labels WHERE production_order_id=? AND label_type=? ORDER BY updated_at DESC');
        $stmt->execute([$orderId,$type]);return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function save(int $orderId,string $type,string $reference,array $values,int $userId): array
    {
        if(!in_array($type,['roll','ink'],true))throw new InvalidArgumentException('Tipo de etiqueta inválido.');
        $reference=trim($reference);if($reference==='')throw new InvalidArgumentException($type==='roll'?'Indique o número do rolo.':'Selecione uma tinta.');
        $clean=[];foreach($values as$key=>$value){$clean[(string)$key]=trim((string)$value);}
        if($type==='roll'&&($clean['net_weight']??'')===''&&($clean['length']??'')===''&&($clean['quantity']??'')==='')throw new InvalidArgumentException('Valide pelo menos a quantidade, o peso líquido ou os metros do rolo.');
        if($type==='ink'&&($clean['ink_code']??'')==='')throw new InvalidArgumentException('O código da tinta é obrigatório.');
        $json=json_encode($clean,JSON_UNESCAPED_UNICODE);$existing=$this->find($orderId,$type,$reference);
        if($existing){$stmt=$this->pdo->prepare('UPDATE erp_production_labels SET values_json=?,validated_by=?,validated_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=?');$stmt->execute([$json,$userId,(int)$existing['id']]);$id=(int)$existing['id'];}
        else{$stmt=$this->pdo->prepare('INSERT INTO erp_production_labels(production_order_id,label_type,reference_key,values_json,validated_by) VALUES (?,?,?,?,?)');$stmt->execute([$orderId,$type,$reference,$json,$userId]);$id=(int)$this->pdo->lastInsertId();}
        $this->pdo->prepare('INSERT INTO erp_production_label_history(production_label_id,values_json,validated_by) VALUES (?,?,?)')->execute([$id,$json,$userId]);
        $this->pdo->prepare('INSERT INTO erp_production_order_audit(production_order_id,user_id,action,new_value_json) VALUES (?,?,"validate_label",?)')->execute([$orderId,$userId,json_encode(['type'=>$type,'reference'=>$reference,'values'=>$clean],JSON_UNESCAPED_UNICODE)]);
        return $this->find($orderId,$type,$reference)?:[];
    }
}
