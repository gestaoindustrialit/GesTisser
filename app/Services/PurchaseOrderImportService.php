<?php
declare(strict_types=1);

final class PurchaseOrderImportService
{
    private $pdo;
    public function __construct(PDO $pdo){$this->pdo=$pdo;}
    public function duplicate(string $hash,?int $supplierId=null,string $reference=''):?array
    {$q=$this->pdo->prepare('SELECT i.*,o.order_number FROM erp_order_imports i LEFT JOIN erp_purchase_orders o ON o.id=i.purchase_order_id WHERE i.file_hash=? LIMIT 1');$q->execute([$hash]);if($r=$q->fetch(PDO::FETCH_ASSOC))return $r;if($supplierId&&$reference!==''){$q=$this->pdo->prepare('SELECT i.*,o.order_number FROM erp_order_imports i JOIN erp_purchase_orders o ON o.id=i.purchase_order_id WHERE o.supplier_id=? AND o.supplier_reference=? LIMIT 1');$q->execute([$supplierId,$reference]);return $q->fetch(PDO::FETCH_ASSOC)?:null;}return null;}
    public function create(array $document,int $importId,int $userId):int
    {
        if($this->pdo->inTransaction())throw new RuntimeException('A confirmação da importação requer uma transação própria.');$this->pdo->beginTransaction();try{
            $supplier=(int)($document['supplier']['id']??0);if(!$supplier)throw new RuntimeException('Confirme o fornecedor antes de criar a encomenda.');
            $seq=$this->pdo->query("SELECT prefix,next_number,padding,suffix FROM erp_number_sequences WHERE code='purchase_order'")->fetch(PDO::FETCH_ASSOC);if(!$seq)throw new RuntimeException('Sequência de encomendas não configurada.');$number=$seq['prefix'].str_pad((string)$seq['next_number'],(int)$seq['padding'],'0',STR_PAD_LEFT).($seq['suffix']??'');$this->pdo->exec("UPDATE erp_number_sequences SET next_number=next_number+1 WHERE code='purchase_order'");
            $this->pdo->prepare('INSERT INTO erp_purchase_orders(order_number,supplier_id,order_date,expected_date,supplier_reference,status,created_by) VALUES(?,?,?,?,?,?,?)')->execute([$number,$supplier,$document['order_date'],$document['delivery_date']??null,$document['order_number']??null,'Aberta',$userId]);$orderId=(int)$this->pdo->lastInsertId();$insert=$this->pdo->prepare('INSERT INTO erp_purchase_order_lines(purchase_order_id,line_number,item_type,item_id,description,quantity,unit_original,unit_normalized,unit_price,line_total,expected_date) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
            $created=0;foreach($document['lines'] as $line){if(($line['type']??'')!=='ARTICLE'||!empty($line['ignored']))continue;if(empty($line['matched_item_id']))throw new RuntimeException('Todas as linhas de artigo devem ser associadas ou ignoradas.');$insert->execute([$orderId,$line['line_number'],$line['matched_item_type']??'raw_material',$line['matched_item_id'],$line['description'],$line['quantity'],$line['unit_original'],$line['unit_normalized'],$line['unit_price'],$line['total'],$line['delivery_date']??null]);$created++;}
            if(!$created)throw new RuntimeException('A encomenda deve conter pelo menos uma linha de artigo.');$this->pdo->prepare('UPDATE erp_order_imports SET purchase_order_id=?,validated_data_json=?,status="completed",validated_by=?,validated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$orderId,json_encode($document,JSON_UNESCAPED_UNICODE),$userId,$importId]);$this->pdo->commit();return $orderId;
        }catch(Throwable $e){$this->pdo->rollBack();throw $e;}
    }
    public function saveMapping(int $supplierId,string $reference,string $itemType,int $itemId,int $userId,bool $authorized=false):void
    {$q=$this->pdo->prepare('SELECT item_type,item_id FROM erp_supplier_item_mappings WHERE supplier_id=? AND supplier_reference=?');$q->execute([$supplierId,$reference]);$old=$q->fetch(PDO::FETCH_ASSOC);if($old&&($old['item_type']!==$itemType||(int)$old['item_id']!==$itemId)&&!$authorized)throw new DomainException('Esta referência já está associada a outro artigo.');if($old)$this->pdo->prepare('UPDATE erp_supplier_item_mappings SET item_type=?,item_id=?,confirmed_by=?,updated_at=CURRENT_TIMESTAMP WHERE supplier_id=? AND supplier_reference=?')->execute([$itemType,$itemId,$userId,$supplierId,$reference]);else $this->pdo->prepare('INSERT INTO erp_supplier_item_mappings(supplier_id,supplier_reference,item_type,item_id,confirmed_by) VALUES(?,?,?,?,?)')->execute([$supplierId,$reference,$itemType,$itemId,$userId]);}
}
