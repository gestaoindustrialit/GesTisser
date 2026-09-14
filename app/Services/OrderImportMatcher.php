<?php
declare(strict_types=1);

final class OrderImportMatcher
{
    private $pdo; private $threshold;
    public function __construct(PDO $pdo,int $threshold=90){$this->pdo=$pdo;$this->threshold=$threshold;}
    public function match(int $supplierId,array $line):array
    {
        $ref=trim((string)($line['supplier_reference']??''));
        if($ref!==''){$q=$this->pdo->prepare('SELECT item_type,item_id FROM erp_supplier_item_mappings WHERE supplier_id=? AND supplier_reference=?');$q->execute([$supplierId,$ref]);if($r=$q->fetch(PDO::FETCH_ASSOC))return $this->result($r,100,'confirmed_mapping');
            foreach([['erp_raw_materials','raw_material'],['erp_finished_products','finished_product']] as $source){$q=$this->pdo->prepare('SELECT id FROM '.$source[0].' WHERE UPPER(code)=UPPER(?) LIMIT 1');$q->execute([$ref]);if($id=$q->fetchColumn())return $this->result(['item_type'=>$source[1],'item_id'=>$id],90,'internal_code');}}
        $description=trim((string)($line['description']??''));if($description!==''){foreach([['erp_raw_materials','raw_material'],['erp_finished_products','finished_product']] as $source){$q=$this->pdo->prepare('SELECT id,description FROM '.$source[0].' WHERE description LIKE ? LIMIT 20');$q->execute(['%'.$description.'%']);if($r=$q->fetch(PDO::FETCH_ASSOC))return $this->result(['item_type'=>$source[1],'item_id'=>$r['id']],70,'description');}}
        return ['matched_item_id'=>null,'matched_item_type'=>null,'confidence'=>0,'match_method'=>'none','requires_confirmation'=>true];
    }
    private function result(array $row,int $confidence,string $method):array{return ['matched_item_id'=>(int)$row['item_id'],'matched_item_type'=>$row['item_type'],'confidence'=>$confidence,'match_method'=>$method,'requires_confirmation'=>$confidence<$this->threshold];}
}
