<?php
/** Read-only customer activity. Native FKs only; no migrations or status synchronisation. */
final class CustomerProfile
{
    private $pdo;
    private $tables=[];
    private $orderScope;
    const PAGE_SIZE=20;
    public function __construct(PDO $pdo, $orderScope='customer_id') {
        if(!in_array($orderScope,['customer_id','finished_product_id'],true))throw new InvalidArgumentException('Invalid history scope');
        $this->pdo=$pdo;$this->orderScope=$orderScope;
    }
    private function exists($table) {
        if(!array_key_exists($table,$this->tables)) {$s=$this->pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");$s->execute([$table]);$this->tables[$table]=(bool)$s->fetchColumn();}
        return $this->tables[$table];
    }
    private function all($sql,array $params=[]) {$s=$this->pdo->prepare($sql);$s->execute($params);return $s->fetchAll(PDO::FETCH_ASSOC);}
    private function row($sql,array $params=[]) {$rows=$this->all($sql,$params);return $rows?$rows[0]:null;}
    public function customer($id) {return $this->row('SELECT * FROM erp_customers WHERE id=?',[$id]);}
    public function addresses($id) {return $this->exists('erp_customer_delivery_addresses')?$this->all('SELECT * FROM erp_customer_delivery_addresses WHERE customer_id=? ORDER BY id',[$id]):[];}
    public static function filters(array $input) {
        $year=isset($input['year'])&&is_scalar($input['year'])?(string)$input['year']:'';
        if(!preg_match('/^(19|20)[0-9]{2}$/D',$year))$year='';
        $id=isset($input['article'])&&is_scalar($input['article'])&&ctype_digit((string)$input['article'])?(int)$input['article']:0;
        $page=isset($input['p'])&&is_scalar($input['p'])&&ctype_digit((string)$input['p'])?min(10000,max(1,(int)$input['p'])):1;
        return ['year'=>$year,'article'=>$id,'status'=>is_scalar($input['status']??'')?mb_substr(trim((string)($input['status']??'')),0,80):'','q'=>is_scalar($input['q']??'')?mb_substr(trim((string)($input['q']??'')),0,120):'','p'=>$page,'sort'=>in_array($input['sort']??'',['newest','oldest','number'],true)?$input['sort']:'newest'];
    }
    private function where($id,array $filters) {
        $where='o.'.$this->orderScope.'=?';$params=[$id];
        if($filters['year']!=='') {$where.=' AND o.created_at>=? AND o.created_at<?';$params[]=$filters['year'].'-01-01';$params[]=((int)$filters['year']+1).'-01-01';}
        if($filters['article']>0) {$where.=' AND o.finished_product_id=?';$params[]=$filters['article'];}
        if($filters['status']!=='') {$where.=' AND o.status=?';$params[]=$filters['status'];}
        if(!empty($filters['from'])) {$where.=' AND o.created_at>=?';$params[]=$filters['from'];}
        if(!empty($filters['to'])) {$where.=' AND o.created_at<?';$params[]=(new DateTime($filters['to']))->modify('+1 day')->format('Y-m-d');}
        return [$where,$params];
    }
    public function summary($id) {
        $summary=['orders'=>null,'last_order'=>null,'ofs'=>null,'quantities'=>[],'produced_articles'=>null,'hours'=>null,'last_activity'=>null];
        if(!$this->exists('erp_production_orders'))return $summary;
        $r=$this->row('SELECT COUNT(*) total,COUNT(DISTINCT CASE WHEN produced_quantity>0 THEN finished_product_id END) produced_articles,MAX(created_at) last_created FROM erp_production_orders WHERE customer_id=?',[$id]);
        $summary['ofs']=(int)$r['total'];$summary['produced_articles']=$r['total']>0?(int)$r['produced_articles']:null;$summary['last_activity']=$r['last_created'];
        $summary['quantities']=$this->all('SELECT SUM(o.produced_quantity) quantity,u.code unit,CASE WHEN u.id IS NULL THEN o.order_number ELSE NULL END unknown_order FROM erp_production_orders o LEFT JOIN erp_finished_products a ON a.id=o.finished_product_id LEFT JOIN erp_products p ON p.id=o.product_id LEFT JOIN erp_units u ON u.id=COALESCE(a.unit_id,p.unit_id) WHERE o.customer_id=? GROUP BY CASE WHEN u.id IS NULL THEN "of:"||o.id ELSE "unit:"||u.id END ORDER BY u.code,o.id',[$id]);
        if($this->exists('erp_operation_time_entries')) {
            $t=$this->row('SELECT COUNT(*) total,SUM(MAX(0,(julianday(t.ended_at)-julianday(t.started_at))*24-COALESCE(t.pause_seconds,0)/3600.0)) hours,MAX(t.ended_at) last_ended FROM erp_operation_time_entries t JOIN erp_production_order_operations op ON op.id=t.production_order_operation_id JOIN erp_production_orders o ON o.id=op.production_order_id WHERE o.customer_id=? AND t.ended_at IS NOT NULL',[$id]);
            $summary['hours']=$t['total']>0?(float)$t['hours']:null;
            if($t['last_ended'] && (!$summary['last_activity'] || $t['last_ended']>$summary['last_activity']))$summary['last_activity']=$t['last_ended'];
        }
        return $summary;
    }
    private function originJoin() {
        return $this->exists('erp_legacy_import_map')?' LEFT JOIN (SELECT gestisser_id,MIN(legacy_id) legacy_id FROM erp_legacy_import_map WHERE source_system="Bobinas" AND target_table="erp_production_orders" GROUP BY gestisser_id) lm ON lm.gestisser_id=o.id ':'';
    }
    private function originSelect() {return $this->exists('erp_legacy_import_map')?'lm.legacy_id':'NULL';}
    public function orders($id,array $filters,$limit=null) {
        if(!$this->exists('erp_production_orders'))return ['rows'=>[],'total'=>0,'page'=>1,'pages'=>1];
        list($where,$params)=$this->where($id,$filters);$count=$this->row('SELECT COUNT(*) total FROM erp_production_orders o WHERE '.$where,$params);$total=(int)$count['total'];
        $size=$limit===null?self::PAGE_SIZE:min(20,max(1,(int)$limit));$pages=max(1,(int)ceil($total/$size));$page=$limit===null?min($pages,$filters['p']):1;$offset=($page-1)*$size;
        $sort=['newest'=>'o.created_at DESC,o.id DESC','oldest'=>'o.created_at,o.id','number'=>'o.order_number,o.id'][$filters['sort']];
        $rows=$this->all('SELECT o.id,o.order_number,o.created_at,o.due_date,o.status,o.planned_quantity,o.produced_quantity,o.finished_product_id,COALESCE(a.code,p.code) article_code,COALESCE(a.description,p.description) article_name,u.code unit,'.$this->originSelect().' legacy_id FROM erp_production_orders o LEFT JOIN erp_finished_products a ON a.id=o.finished_product_id LEFT JOIN erp_products p ON p.id=o.product_id LEFT JOIN erp_units u ON u.id=COALESCE(a.unit_id,p.unit_id) '.$this->originJoin().' WHERE '.$where.' ORDER BY '.$sort.' LIMIT '.$size.' OFFSET '.$offset,$params);
        return ['rows'=>$rows,'total'=>$total,'page'=>$page,'pages'=>$pages];
    }
    public function options($id) {
        return ['years'=>$this->all('SELECT DISTINCT substr(created_at,1,4) year FROM erp_production_orders WHERE customer_id=? ORDER BY year DESC',[$id]),'statuses'=>$this->all('SELECT DISTINCT status FROM erp_production_orders WHERE customer_id=? ORDER BY status',[$id]),'articles'=>$this->all('SELECT a.id,a.code,a.description FROM erp_finished_products a WHERE a.customer_id=? OR EXISTS(SELECT 1 FROM erp_production_orders o WHERE o.customer_id=? AND o.finished_product_id=a.id) ORDER BY a.code',[$id,$id])];
    }
    public function articles($id,array $filters) {
        // Includes explicit catalogue customer FK and articles explicitly referenced by this customer's OFs.
        $where='(a.customer_id=? OR EXISTS(SELECT 1 FROM erp_production_orders x WHERE x.customer_id=? AND x.finished_product_id=a.id))';$params=[$id,$id];
        if($filters['q']!=='') {$where.=' AND (a.code LIKE ? ESCAPE "\\" OR a.description LIKE ? ESCAPE "\\")';$q='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$filters['q']).'%';$params[]=$q;$params[]=$q;}
        $total=(int)$this->row('SELECT COUNT(*) total FROM erp_finished_products a WHERE '.$where,$params)['total'];$pages=max(1,(int)ceil($total/self::PAGE_SIZE));$page=min($pages,$filters['p']);
        $rows=$this->all('SELECT a.id,a.code,a.description,u.code unit,activity.productions,activity.quantity,activity.last_production FROM erp_finished_products a LEFT JOIN erp_units u ON u.id=a.unit_id LEFT JOIN (SELECT finished_product_id,COUNT(*) productions,SUM(produced_quantity) quantity,MAX(created_at) last_production FROM erp_production_orders WHERE customer_id=? AND produced_quantity>0 GROUP BY finished_product_id) activity ON activity.finished_product_id=a.id WHERE '.$where.' ORDER BY a.code,a.id LIMIT '.self::PAGE_SIZE.' OFFSET '.(($page-1)*self::PAGE_SIZE),array_merge([$id],$params));
        return ['rows'=>$rows,'total'=>$total,'page'=>$page,'pages'=>$pages];
    }
    public function frequent($id) {return $this->all('SELECT a.id,a.code,a.description,COUNT(*) productions FROM erp_production_orders o JOIN erp_finished_products a ON a.id=o.finished_product_id WHERE o.customer_id=? AND o.produced_quantity>0 GROUP BY a.id ORDER BY productions DESC,a.code LIMIT 5',[$id]);}
    public function costs($id,array $filters) {
        $list=$this->orders($id,$filters);$ids=array_column($list['rows'],'id');$list['times']=[];$list['operations']=[];$list['costs']=[];$list['closures']=[];
        if(!$ids)return $list;$in=implode(',',array_fill(0,count($ids),'?'));
        if($this->exists('erp_operation_time_entries')) {
            $rows=$this->all('SELECT op.production_order_id,op.id,operation.name operation_name,COUNT(*) entries,SUM(MAX(0,(julianday(t.ended_at)-julianday(t.started_at))*24-COALESCE(t.pause_seconds,0)/3600.0)) hours FROM erp_operation_time_entries t JOIN erp_production_order_operations op ON op.id=t.production_order_operation_id JOIN erp_operations operation ON operation.id=op.operation_id WHERE op.production_order_id IN ('.$in.') AND t.ended_at IS NOT NULL GROUP BY op.id ORDER BY op.production_order_id,op.sequence_no',$ids);
            foreach($rows as $r){$of=$r['production_order_id'];$list['times'][$of]=($list['times'][$of]??0)+(float)$r['hours'];$list['operations'][$of][]=$r;}
        }
        if($this->exists('erp_production_order_costs'))foreach($this->all('SELECT production_order_id,category,SUM(actual_amount) amount FROM erp_production_order_costs WHERE production_order_id IN ('.$in.') GROUP BY production_order_id,category',$ids) as $r)$list['costs'][$r['production_order_id']][]=$r;
        if($this->exists('erp_production_order_closures'))foreach($this->all('SELECT production_order_id,total_actual_cost,closed_at FROM erp_production_order_closures WHERE production_order_id IN ('.$in.')',$ids) as $r)$list['closures'][$r['production_order_id']]=$r;
        return $list;
    }
    public function trace($id,array $filters) {
        $list=$this->orders($id,$filters);$ids=array_column($list['rows'],'id');$list['consumptions']=[];$list['rolls']=[];$list['truncated']=false;
        if(!$ids)return $list;$in=implode(',',array_fill(0,count($ids),'?'));
        if($this->exists('erp_production_consumptions')) {
            $inkJoin=$this->exists('erp_raw_material_ink_labels')?' LEFT JOIN erp_raw_material_ink_labels ink ON c.stock_unit_type="INK" AND ink.id=c.stock_unit_id ':'';
            $inkSelect=$inkJoin?'ink.barcode ink_barcode,ink.supplier_lot ink_lot':'NULL ink_barcode,NULL ink_lot';
            $rows=$this->all('SELECT '.$inkSelect.',c.production_order_id,c.quantity,c.unit_code,c.lot,c.created_at,c.stock_unit_type,c.stock_unit_id,c.source_movement_id,rm.code material_code,rm.description material_name,m.movement_number FROM erp_production_consumptions c LEFT JOIN erp_raw_materials rm ON rm.id=c.raw_material_id LEFT JOIN erp_stock_movements m ON m.id=c.source_movement_id '.$inkJoin.' WHERE c.production_order_id IN ('.$in.') ORDER BY c.production_order_id,c.id LIMIT 201',$ids);
            if(count($rows)>200){$list['truncated']=true;array_pop($rows);}
            foreach($rows as $r)$list['consumptions'][$r['production_order_id']][]=$r;
        }
        if($this->exists('erp_raw_material_roll_consumptions')){
            $rows=$this->all('SELECT c.production_order_id,c.source_label_id,l.barcode,l.supplier_lot,c.consumed_metres,c.consumed_weight_kg,c.created_at FROM erp_raw_material_roll_consumptions c JOIN erp_raw_material_roll_labels l ON l.id=c.source_label_id WHERE c.production_order_id IN ('.$in.') ORDER BY c.id LIMIT 201',$ids);
            if(count($rows)>200){$list['truncated']=true;array_pop($rows);}
            foreach($rows as $r)$list['rolls'][$r['production_order_id']][]=$r;
        }
        return $list;
    }
}
