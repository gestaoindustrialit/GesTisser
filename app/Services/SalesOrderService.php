<?php
require_once __DIR__.'/NumberSequenceService.php';
require_once __DIR__.'/SalesOrderSchema.php';
/** Commercial records use exact FKs; production and logistics remain independent. */
final class SalesOrderService
{
    private $pdo;
    const PAGE_SIZE=20;
    public static function statuses(){return ['Rascunho','Confirmada','Em planeamento','Em produção','Parcialmente concluída','Concluída','Cancelada'];}
    public function __construct(PDO $pdo){$this->pdo=$pdo;}
    public function all($sql,array $params=[]){$s=$this->pdo->prepare($sql);$s->execute($params);return $s->fetchAll(PDO::FETCH_ASSOC);}
    public function row($sql,array $params=[]){$r=$this->all($sql,$params);return $r?$r[0]:null;}
    public static function text(array $a,$key,$default=''){return isset($a[$key])&&is_scalar($a[$key])?trim((string)$a[$key]):$default;}
    public static function date($value,$required=false){
        if($value===''&&!$required)return null;
        $d=DateTime::createFromFormat('!Y-m-d',$value);
        if(!$d||$d->format('Y-m-d')!==$value)throw new RuntimeException('Data inválida.');return $value;
    }
    private static function number($value,$optional=false){
        if($value===''&&$optional)return null;
        if(!is_scalar($value)||!is_numeric(str_replace(',','.',(string)$value)))throw new RuntimeException('Quantidade ou valor inválido.');
        $n=(float)str_replace(',','.',(string)$value);if(!is_finite($n)||$n<0||$n>1e12)throw new RuntimeException('Valor fora dos limites.');return $n;
    }
    public static function filters(array $a){
        $f=[];foreach(['q','customer','article','status','from','to','late','sort','dir'] as $k)$f[$k]=self::text($a,$k);
        foreach(['from','to'] as $k){try{$f[$k]=self::date($f[$k])?:'';}catch(RuntimeException $e){$f[$k]='';}}
        $f['customer']=ctype_digit($f['customer'])?(int)$f['customer']:0;$f['article']=ctype_digit($f['article'])?(int)$f['article']:0;
        $f['q']=mb_substr($f['q'],0,120);$f['status']=mb_substr($f['status'],0,80);
        $f['p']=min(10000,max(1,(int)self::text($a,'p','1')));$f['dir']=$f['dir']==='asc'?'asc':'desc';
        if(!in_array($f['sort'],['number','customer','date','expected','lines','status','source'],true))$f['sort']='date';return $f;
    }
    public function listing(array $f){
        $where=['1=1'];$p=[];
        if($f['customer']){$where[]='o.customer_id=?';$p[]=$f['customer'];}
        if($f['article']){$where[]='EXISTS(SELECT 1 FROM erp_sales_order_lines l WHERE l.sales_order_id=o.id AND l.finished_product_id=?)';$p[]=$f['article'];}
        if($f['q']!==''){
            $where[]='(o.order_number LIKE ? ESCAPE "\\" OR c.code LIKE ? ESCAPE "\\" OR c.name LIKE ? ESCAPE "\\" OR EXISTS(SELECT 1 FROM erp_sales_order_lines l WHERE l.sales_order_id=o.id AND (l.article_code LIKE ? ESCAPE "\\" OR l.description LIKE ? ESCAPE "\\")))';
            $q='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$f['q']).'%';for($i=0;$i<5;$i++)$p[]=$q;
        }
        if($f['status']!==''){$where[]='o.status=?';$p[]=$f['status'];}
        if($f['from']!==''){$where[]='o.order_date>=?';$p[]=$f['from'];}if($f['to']!==''){$where[]='o.order_date<=?';$p[]=$f['to'];}
        if($f['late']==='1'){
            $where[]='o.status NOT IN ("Cancelada","Concluída") AND EXISTS(SELECT 1 FROM erp_sales_order_lines l WHERE l.sales_order_id=o.id AND COALESCE(l.expected_date,o.expected_date)<? AND l.quantity>COALESCE((SELECT SUM(ABS(m.quantity)) FROM erp_sales_order_deliveries d JOIN erp_stock_movements m ON m.id=d.stock_movement_id WHERE d.sales_order_line_id=l.id AND NOT EXISTS(SELECT 1 FROM erp_stock_movements rev WHERE rev.reversal_of_id=m.id)),0))';$p[]=date('Y-m-d');
        }
        $join=' FROM erp_sales_orders o JOIN erp_customers c ON c.id=o.customer_id WHERE '.implode(' AND ',$where);
        $total=(int)$this->row('SELECT COUNT(*) n'.$join,$p)['n'];$pages=max(1,(int)ceil($total/self::PAGE_SIZE));$page=min($pages,$f['p']);
        $sort=['number'=>'o.order_number','customer'=>'c.name','date'=>'o.order_date','expected'=>'o.expected_date','lines'=>'article_count','status'=>'o.status','source'=>'o.source_system'][$f['sort']];
        $rows=$this->all('SELECT o.*,c.code customer_code,c.name customer_name,(SELECT COUNT(*) FROM erp_sales_order_lines l WHERE l.sales_order_id=o.id) line_count,(SELECT COUNT(DISTINCT finished_product_id) FROM erp_sales_order_lines l WHERE l.sales_order_id=o.id) article_count'.$join.' ORDER BY '.$sort.' '.$f['dir'].',o.id '.$f['dir'].' LIMIT '.self::PAGE_SIZE.' OFFSET '.(($page-1)*self::PAGE_SIZE),$p);
        if($rows){$ids=array_column($rows,'id');$lines=$this->all('SELECT sales_order_id,id,unit_id,unit_code,quantity FROM erp_sales_order_lines WHERE sales_order_id IN ('.implode(',',array_fill(0,count($ids),'?')).')',$ids);$groups=[];foreach($lines as $l)$groups[$l['sales_order_id']][]=$l;foreach($rows as &$r)$r['quantities']=self::totals($groups[$r['id']]??[],'quantity');unset($r);}
        return ['rows'=>$rows,'total'=>$total,'page'=>$page,'pages'=>$pages];
    }
    public function order($id){return $this->row('SELECT o.*,c.code customer_code,c.name customer_name,c.is_active customer_active FROM erp_sales_orders o JOIN erp_customers c ON c.id=o.customer_id WHERE o.id=?',[$id]);}
    public function lines($id){
        $lines=$this->all('SELECT l.*,a.status article_status,a.customer_id article_customer_id FROM erp_sales_order_lines l JOIN erp_finished_products a ON a.id=l.finished_product_id WHERE l.sales_order_id=? ORDER BY l.line_number',[$id]);
        $ofs=$this->workOrders($id);$deliveries=$this->deliveries($id);
        foreach($lines as &$l){$l['planned']=0;$l['produced']=0;$l['delivered']=0;$l['of_count']=0;
            foreach($ofs as $of)if($of['sales_order_line_id']==$l['id']){++$l['of_count'];$l['produced']+=(float)$of['produced_quantity'];if($of['status']!=='Cancelada')$l['planned']+=(float)$of['planned_quantity'];}
            foreach($deliveries as $d)if($d['sales_order_line_id']==$l['id']&&!$d['reversed'])$l['delivered']+=abs((float)$d['quantity']);
            $l['pending']=max(0,(float)$l['quantity']-$l['delivered']);$l['production_state']=$l['produced']>=$l['quantity']?'Concluída':($l['produced']>0?'Parcialmente produzida':($l['of_count']?'Planeada':'Sem OF'));
            $l['logistic_state']=$l['delivered']>=$l['quantity']?'Entregue':($l['delivered']>0?'Entrega parcial':'Sem entrega comprovada');
        }unset($l);return $lines;
    }
    public static function totals(array $lines,$field){
        $totals=[];foreach($lines as $l){$key=$l['unit_id']===null?'line:'.$l['id']:'unit:'.$l['unit_id'];if(!isset($totals[$key]))$totals[$key]=['quantity'=>0,'unit'=>$l['unit_code']?:'Unidade não registada · linha '.($l['line_number']??$l['id'])];$totals[$key]['quantity']+=(float)$l[$field];}return array_values($totals);
    }
    public function workOrders($id){return $this->all('SELECT w.sales_order_line_id,o.*,l.article_code,l.unit_code FROM erp_sales_order_work_orders w JOIN erp_sales_order_lines l ON l.id=w.sales_order_line_id JOIN erp_production_orders o ON o.id=w.production_order_id WHERE l.sales_order_id=? ORDER BY l.line_number,o.id',[$id]);}
    public function deliveries($id){return $this->all('SELECT d.*,m.movement_number,m.movement_date,m.quantity,l.unit_code,l.article_code,EXISTS(SELECT 1 FROM erp_stock_movements rev WHERE rev.reversal_of_id=m.id) reversed FROM erp_sales_order_deliveries d JOIN erp_sales_order_lines l ON l.id=d.sales_order_line_id JOIN erp_stock_movements m ON m.id=d.stock_movement_id WHERE l.sales_order_id=? ORDER BY m.movement_date,d.id',[$id]);}
    public function history($id){return $this->all('SELECT action,old_values_json,new_values_json,created_at,reason FROM erp_audit_log WHERE entity="erp_sales_orders" AND entity_id=? ORDER BY id DESC LIMIT 100',[$id]);}
    public function costs($id){return $this->all('SELECT DISTINCT o.id,o.order_number,c.total_planned_cost,c.total_actual_cost,c.closed_at FROM erp_sales_order_work_orders w JOIN erp_sales_order_lines l ON l.id=w.sales_order_line_id JOIN erp_production_orders o ON o.id=w.production_order_id LEFT JOIN erp_production_order_closures c ON c.production_order_id=o.id WHERE l.sales_order_id=? ORDER BY o.id',[$id]);}
    public function documents($id){return $this->all('SELECT id,title,document_type,version,status,file_url FROM erp_product_documents WHERE entity_type="sales_order" AND entity_id=? ORDER BY id DESC',[$id]);}
    private function audit($user,$action,$id,array $old,array $new,$reason=null){$this->pdo->prepare('INSERT INTO erp_audit_log(user_id,action,entity,entity_id,old_values_json,new_values_json,reason) VALUES (?,?,"erp_sales_orders",?,?,?,?)')->execute([$user,$action,$id,json_encode($old,JSON_UNESCAPED_UNICODE),json_encode($new,JSON_UNESCAPED_UNICODE),$reason]);}
    /** Caller owns the transaction and permission checks. No removals, renumbering or price lookup. */
    public function save(array $input,$user,$mayConfirm){
        $id=(int)self::text($input,'id','0');$old=$id?$this->order($id):null;
        if($id&&!$old)throw new RuntimeException('Encomenda não encontrada.');
        if($old&&($old['source_system']!=='GesTISSER'||$old['original_id']!==null))throw new RuntimeException('Registos históricos são apenas de consulta.');
        $customer=(int)self::text($input,'customer_id');$c=$this->row('SELECT * FROM erp_customers WHERE id=?',[$customer]);
        if(!$c||(!$c['is_active']&&(!$old||$old['customer_id']!=$customer)))throw new RuntimeException('Selecione um cliente ativo.');
        $status=self::text($input,'status','Rascunho');if(!in_array($status,self::statuses(),true)&&(!$old||$status!==$old['status']))throw new RuntimeException('Estado inválido.');
        if($status!=='Rascunho'&&(!$old||$status!==$old['status'])&&!$mayConfirm)throw new RuntimeException('Sem permissão para alterar o estado comercial.');
        $date=self::date(self::text($input,'order_date'),true);$expected=self::date(self::text($input,'expected_date'));
        $posted=$input['lines']??[];if(!is_array($posted)||count($posted)<1||count($posted)>200)throw new RuntimeException('Registe entre 1 e 200 linhas.');
        $oldLines=$old?$this->lines($id):[];$byId=[];foreach($oldLines as $l)$byId[$l['id']]=$l;$seen=[];$newLines=[];
        foreach($posted as $l){if(!is_array($l))throw new RuntimeException('Linha inválida.');$lineId=(int)self::text($l,'id');$before=$byId[$lineId]??null;
            if($lineId&&(!$before||isset($seen[$lineId])))throw new RuntimeException('Linha não pertence à encomenda ou está repetida.');if($lineId)$seen[$lineId]=true;
            $article=(int)self::text($l,'finished_product_id');$a=$this->row('SELECT a.*,u.code unit_code FROM erp_finished_products a LEFT JOIN erp_units u ON u.id=a.unit_id WHERE a.id=?',[$article]);
            if(!$a)throw new RuntimeException('Artigo inexistente.');
            $same=$before&&$article==$before['finished_product_id']&&$old['customer_id']==$customer;
            if(!$same&&($a['status']!=='Ativo'||($a['customer_id']&&$a['customer_id']!=$customer)))throw new RuntimeException('O artigo deve estar ativo e pertencer ao cliente selecionado, ou ser genérico.');
            $qty=self::number(self::text($l,'quantity'));if($qty<=0)throw new RuntimeException('A quantidade deve ser positiva.');$price=self::number(self::text($l,'unit_price'),true);$discount=self::number(self::text($l,'discount_percent'),true);if($discount!==null&&$discount>100)throw new RuntimeException('Desconto inválido.');
            if($before&&($before['of_count']||$this->row('SELECT id FROM erp_sales_order_deliveries WHERE sales_order_line_id=? LIMIT 1',[$lineId]))&&(!$same||$qty!=(float)$before['quantity']))throw new RuntimeException('Não altere cliente, artigo ou quantidade de uma linha com OF ou entregas associadas.');
            // Preserve stored commercial totals unless the user changes price, discount or quantity.
            $unchanged=$before&&$qty==(float)$before['quantity']&&$price===($before['unit_price']===null?null:(float)$before['unit_price'])&&$discount===($before['discount_percent']===null?null:(float)$before['discount_percent']);
            $newLines[]=['id'=>$lineId,'finished_product_id'=>$article,'article_code'=>$same?$before['article_code']:$a['code'],'description'=>self::text($l,'description')?:($same?$before['description']:$a['description']),'quantity'=>$qty,'unit_id'=>$same?$before['unit_id']:$a['unit_id'],'unit_code'=>$same?$before['unit_code']:$a['unit_code'],'unit_price'=>$price,'discount_percent'=>$discount,'line_total'=>$unchanged?$before['line_total']:($price===null?null:round($qty*$price*(1-($discount??0)/100),2)),'expected_date'=>self::date(self::text($l,'expected_date'))];
        }
        if(count($seen)!==count($oldLines))throw new RuntimeException('Não é permitido eliminar linhas existentes.');
        $values=[$customer,$date,$expected,self::text($input,'customer_reference'),$status,self::text($input,'notes'),$user];
        if($old){$s=$this->pdo->prepare('UPDATE erp_sales_orders SET customer_id=?,order_date=?,expected_date=?,customer_reference=?,status=?,notes=?,updated_by=?,revision=revision+1,updated_at=CURRENT_TIMESTAMP WHERE id=? AND revision=?');$s->execute(array_merge($values,[$id,(int)self::text($input,'revision')]));if($s->rowCount()!==1)throw new RuntimeException('A encomenda foi alterada entretanto. Reabra a ficha antes de guardar.');}
        else{$number=NumberSequenceService::take($this->pdo,'sales_order');$s=$this->pdo->prepare('INSERT INTO erp_sales_orders(customer_id,order_date,expected_date,customer_reference,status,notes,created_by,order_number,updated_by) VALUES (?,?,?,?,?,?,?,?,?)');$s->execute(array_merge($values,[$number,$user]));$id=(int)$this->pdo->lastInsertId();}
        foreach($newLines as $index=>$l){$lineId=$l['id'];unset($l['id']);$values=array_values($l);if($lineId)$this->pdo->prepare('UPDATE erp_sales_order_lines SET finished_product_id=?,article_code=?,description=?,quantity=?,unit_id=?,unit_code=?,unit_price=?,discount_percent=?,line_total=?,expected_date=? WHERE id=? AND sales_order_id=?')->execute(array_merge($values,[$lineId,$id]));else $this->pdo->prepare('INSERT INTO erp_sales_order_lines(finished_product_id,article_code,description,quantity,unit_id,unit_code,unit_price,discount_percent,line_total,expected_date,sales_order_id,line_number) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')->execute(array_merge($values,[$id,$index+1]));}
        $this->audit($user,$old?'update':'create',$id,['order'=>$old,'lines'=>$oldLines],['order'=>$this->order($id),'lines'=>$newLines]);return $id;
    }
    private function mutableLine($orderId,$lineId){$o=$this->order($orderId);if(!$o||in_array($o['status'],['Rascunho','Cancelada','Concluída'],true)||$o['source_system']!=='GesTISSER'||$o['original_id']!==null)throw new RuntimeException('A encomenda não permite novas associações.');$l=$this->row('SELECT * FROM erp_sales_order_lines WHERE id=? AND sales_order_id=?',[$lineId,$orderId]);if(!$l)throw new RuntimeException('Linha inválida.');return [$o,$l];}
    public function linkWorkOrder($orderId,$lineId,$ofId,$user){
        list($o,$l)=$this->mutableLine($orderId,$lineId);$of=$this->row('SELECT p.*,a.unit_id FROM erp_production_orders p JOIN erp_finished_products a ON a.id=p.finished_product_id WHERE p.id=?',[$ofId]);
        if(!$of||$of['customer_id']!=$o['customer_id']||$of['finished_product_id']!=$l['finished_product_id']||$l['unit_id']===null||$of['unit_id']!=$l['unit_id'])throw new RuntimeException('OF, cliente, artigo e unidade devem corresponder à linha.');
        $this->pdo->prepare('INSERT INTO erp_sales_order_work_orders(sales_order_line_id,production_order_id,linked_by) VALUES (?,?,?)')->execute([$lineId,$ofId,$user]);$this->audit($user,'link_work_order',$orderId,[],['line_id'=>$lineId,'production_order_id'=>$ofId]);
    }
    public function linkDelivery($orderId,$lineId,$movementId,$reference,$user){
        list($o,$l)=$this->mutableLine($orderId,$lineId);$m=$this->row('SELECT m.*,a.unit_id FROM erp_stock_movements m JOIN erp_finished_products a ON a.id=m.item_id WHERE m.id=? AND m.item_type="finished_product" AND m.movement_type="Saída" AND m.reversal_of_id IS NULL AND NOT EXISTS(SELECT 1 FROM erp_stock_movements rev WHERE rev.reversal_of_id=m.id)',[$movementId]);
        if(!$m||$m['item_id']!=$l['finished_product_id']||$l['unit_id']===null||$m['unit_id']!=$l['unit_id']||abs((float)$m['quantity'])<=0||trim($reference)==='')throw new RuntimeException('Selecione uma saída válida do artigo e indique o documento de entrega.');
        $this->pdo->prepare('INSERT INTO erp_sales_order_deliveries(sales_order_line_id,stock_movement_id,delivery_reference,confirmed_by) VALUES (?,?,?,?)')->execute([$lineId,$movementId,trim($reference),$user]);$this->audit($user,'link_delivery',$orderId,[],['line_id'=>$lineId,'movement_id'=>$movementId,'reference'=>$reference]);
    }
}
