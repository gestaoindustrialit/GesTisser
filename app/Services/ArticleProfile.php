<?php
require_once __DIR__.'/CustomerProfile.php';
/** Consultation only. No migrations, current tariffs or status synchronisation. */
final class ArticleProfile
{
    private $pdo;
    private $history;
    private $tables=[];
    public function __construct(PDO $pdo) {$this->pdo=$pdo;$this->history=new CustomerProfile($pdo,'finished_product_id');}
    private function exists($table) {
        if(!array_key_exists($table,$this->tables)){$s=$this->pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");$s->execute([$table]);$this->tables[$table]=(bool)$s->fetchColumn();}
        return $this->tables[$table];
    }
    private function all($sql,array $params=[]) {$s=$this->pdo->prepare($sql);$s->execute($params);return $s->fetchAll(PDO::FETCH_ASSOC);}
    private function row($sql,array $params=[]) {$r=$this->all($sql,$params);return $r?$r[0]:null;}
    public static function filters(array $input) {
        $f=CustomerProfile::filters($input);$f['article']=0;
        foreach(['from','to'] as $key){$v=is_scalar($input[$key]??'')?(string)($input[$key]??''):'';$d=DateTime::createFromFormat('!Y-m-d',$v);$f[$key]=$d&&$d->format('Y-m-d')===$v?$v:'';}
        return $f;
    }
    public function article($id) {return $this->row('SELECT a.*,c.name customer_name,u.code unit FROM erp_finished_products a LEFT JOIN erp_customers c ON c.id=a.customer_id LEFT JOIN erp_units u ON u.id=a.unit_id WHERE a.id=?',[$id]);}
    public function summary($id,$financial=false) {
        $s=['orders'=>null,'ofs'=>null,'quantity'=>null,'last_production'=>null,'hours'=>null,'average_cost'=>null,'closed_ofs'=>0];
        if(!$this->exists('erp_production_orders'))return $s;
        $r=$this->row('SELECT COUNT(*) total,SUM(produced_quantity) quantity FROM erp_production_orders WHERE finished_product_id=?',[$id]);$s['ofs']=(int)$r['total'];$s['quantity']=$r['quantity']===null?null:(float)$r['quantity'];
        if($this->exists('erp_operation_time_entries')){
            $r=$this->row('SELECT SUM(MAX(0,(julianday(t.ended_at)-julianday(t.started_at))*24-COALESCE(t.pause_seconds,0)/3600.0)) hours,MAX(CASE WHEN t.quantity_good>0 THEN t.ended_at END) last_production FROM erp_operation_time_entries t JOIN erp_production_order_operations op ON op.id=t.production_order_operation_id JOIN erp_production_orders o ON o.id=op.production_order_id WHERE o.finished_product_id=? AND t.ended_at IS NOT NULL AND julianday(t.ended_at) IS NOT NULL AND julianday(t.started_at) IS NOT NULL',[$id]);
            $s['hours']=$r['hours']===null?null:(float)$r['hours'];$s['last_production']=$r['last_production'];
        }
        if($this->exists('erp_production_order_closures')){
            $r=$this->row('SELECT MAX(c.closed_at) last_production FROM erp_production_order_closures c JOIN erp_production_orders o ON o.id=c.production_order_id WHERE o.finished_product_id=? AND o.produced_quantity>0',[$id]);
            if($r['last_production']&&(!$s['last_production']||$r['last_production']>$s['last_production']))$s['last_production']=$r['last_production'];
            if($financial){$r=$this->row('SELECT AVG(c.total_actual_cost) average_cost,COUNT(*) total FROM erp_production_order_closures c JOIN erp_production_orders o ON o.id=c.production_order_id WHERE o.finished_product_id=? AND o.produced_quantity>0 AND c.total_actual_cost IS NOT NULL',[$id]);$s['average_cost']=$r['average_cost']===null?null:(float)$r['average_cost'];$s['closed_ofs']=(int)$r['total'];}
        }
        return $s;
    }
    public function options($id) {return $this->exists('erp_production_orders')?$this->all('SELECT DISTINCT status FROM erp_production_orders WHERE finished_product_id=? ORDER BY status',[$id]):[];}
    public function history($id,array $f,$tab='history',$limit=null) {
        // Shared customer reader uses a strictly whitelisted article FK scope.
        // Date ranges are passed through the same prepared query contract.
        $list=$tab==='trace'?$this->history->trace($id,$f):$this->history->orders($id,$f,$limit);
        $ids=array_column($list['rows'],'id');$list['operations']=[];$list['closures']=[];$list['costs']=[];$list['customers']=[];$list['times']=[];$list['operation_hours']=[];$list['cost_consumptions']=[];$list['planned']=[];$list['sheets']=[];$list['reservations']=[];
        if(!$ids)return $list;$in=implode(',',array_fill(0,count($ids),'?'));
        foreach($this->all('SELECT o.id,c.id customer_id,c.name customer_name FROM erp_production_orders o LEFT JOIN erp_customers c ON c.id=o.customer_id WHERE o.id IN ('.$in.')',$ids) as $r)$list['customers'][$r['id']]=$r;
        if($this->exists('erp_production_order_operations')){
            $rows=$this->all('SELECT op.*,COALESCE(NULLIF(op.operation_name,""),operation.name,"Operação histórica") name FROM erp_production_order_operations op LEFT JOIN erp_operations operation ON operation.id=op.operation_id WHERE op.production_order_id IN ('.$in.') ORDER BY op.production_order_id,op.sequence_no,op.id',$ids);
            foreach($rows as $r){$snapshot=json_decode($r['snapshot_json']??'',true)?:[];if(!empty($r['operation_name']))$r['name']=$r['operation_name'];elseif(!empty($snapshot['name']))$r['name']=$snapshot['name'];$list['operations'][$r['production_order_id']][]=$r;}
        }
        if($this->exists('erp_technical_sheets'))foreach($this->all('SELECT id,production_order_id FROM erp_technical_sheets WHERE production_order_id IN ('.$in.')',$ids) as $r)$list['sheets'][$r['production_order_id']]=$r['id'];
        if($tab==='costs'){
            if($this->exists('erp_operation_time_entries'))foreach($this->all('SELECT op.production_order_id,SUM(MAX(0,(julianday(t.ended_at)-julianday(t.started_at))*24-COALESCE(t.pause_seconds,0)/3600.0)) hours FROM erp_operation_time_entries t JOIN erp_production_order_operations op ON op.id=t.production_order_operation_id WHERE op.production_order_id IN ('.$in.') AND t.ended_at IS NOT NULL AND julianday(t.ended_at) IS NOT NULL AND julianday(t.started_at) IS NOT NULL GROUP BY op.production_order_id',$ids) as $r)$list['times'][$r['production_order_id']]=(float)$r['hours'];
            if($this->exists('erp_production_order_closures'))foreach($this->all('SELECT * FROM erp_production_order_closures WHERE production_order_id IN ('.$in.')',$ids) as $r)$list['closures'][$r['production_order_id']]=$r;
            if($this->exists('erp_production_order_costs'))foreach($this->all('SELECT production_order_id,category,SUM(planned_amount) planned,SUM(actual_amount) actual FROM erp_production_order_costs WHERE production_order_id IN ('.$in.') GROUP BY production_order_id,category',$ids) as $r)$list['costs'][$r['production_order_id']][]=$r;
            if($this->exists('erp_production_order_routing_snapshots'))foreach($this->all('SELECT production_order_id,total_planned_minutes FROM erp_production_order_routing_snapshots WHERE production_order_id IN ('.$in.')',$ids) as $r)$list['planned'][$r['production_order_id']]=(float)$r['total_planned_minutes']/60;
            if($this->exists('erp_operation_time_entries'))foreach($this->all('SELECT op.id,SUM(MAX(0,(julianday(t.ended_at)-julianday(t.started_at))*24-COALESCE(t.pause_seconds,0)/3600.0)) hours FROM erp_operation_time_entries t JOIN erp_production_order_operations op ON op.id=t.production_order_operation_id WHERE op.production_order_id IN ('.$in.') AND t.ended_at IS NOT NULL AND julianday(t.ended_at) IS NOT NULL AND julianday(t.started_at) IS NOT NULL GROUP BY op.id',$ids) as $r)$list['operation_hours'][$r['id']]=(float)$r['hours'];
            if($this->exists('erp_production_consumptions')){
                $rows=$this->all('SELECT c.production_order_id,c.quantity,c.unit_code,c.unit_cost,c.lot,rm.code,rm.description FROM erp_production_consumptions c LEFT JOIN erp_raw_materials rm ON rm.id=c.raw_material_id WHERE c.production_order_id IN ('.$in.') ORDER BY c.id LIMIT 201',$ids);
                $list['cost_consumptions_truncated']=count($rows)>200;
                foreach(array_slice($rows,0,200) as $r)$list['cost_consumptions'][$r['production_order_id']][]=$r;
            }
        }
        if($tab==='trace'){
            if($this->exists('erp_production_order_material_reservations')){
                $rows=$this->all('SELECT r.*,rm.code,u.code unit FROM erp_production_order_material_reservations r LEFT JOIN erp_raw_materials rm ON rm.id=r.material_id LEFT JOIN erp_units u ON u.id=rm.primary_unit_id WHERE r.production_order_id IN ('.$in.') ORDER BY r.id LIMIT 201',$ids);
                $list['reservations_truncated']=count($rows)>200;
                foreach(array_slice($rows,0,200) as $r)$list['reservations'][$r['production_order_id']][]=$r;
            }
        }
        return $list;
    }
    public function routing($id,$selected=0) {
        $result=['versions'=>[],'active'=>null,'selected'=>null,'steps'=>[],'materials'=>[]];
        if(!$this->exists('erp_article_routings'))return $result;
        $result['versions']=$this->all('SELECT v.*,r.name routing_name FROM erp_article_routing_versions v JOIN erp_article_routings r ON r.id=v.routing_id WHERE r.finished_product_id=? ORDER BY v.version_no DESC,v.id DESC',[$id]);
        // Same eligibility and ordering used when RoutingService snapshots an OF.
        $result['active']=$this->activeRouting($id);
        foreach($result['versions'] as $v){if((int)$v['id']===(int)$selected)$result['selected']=$v;}
        if(!$result['selected'])$result['selected']=$result['active'];
        if(!$result['selected'])return $result;
        $result['steps']=$this->all('SELECT s.*,o.name operation_name,w.name work_center_name,m.name machine_name FROM erp_article_routing_steps s LEFT JOIN erp_operations o ON o.id=s.operation_id LEFT JOIN erp_work_centers w ON w.id=s.work_center_id LEFT JOIN erp_machines m ON m.id=s.primary_machine_id WHERE s.routing_version_id=? ORDER BY s.sort_order,s.id',[$result['selected']['id']]);
        $ids=array_column($result['steps'],'id');if(!$ids)return $result;$in=implode(',',array_fill(0,count($ids),'?'));
        foreach($this->all('SELECT x.*,rm.code,rm.description,u.code unit FROM erp_routing_step_materials x LEFT JOIN erp_raw_materials rm ON rm.id=x.material_id LEFT JOIN erp_units u ON u.id=rm.primary_unit_id WHERE x.routing_step_id IN ('.$in.') ORDER BY x.id',$ids) as $r)$result['materials'][$r['routing_step_id']][]=$r;
        foreach(['machines'=>['erp_routing_step_machines','machine_id','erp_machines'],'centers'=>['erp_routing_step_work_centers','work_center_id','erp_work_centers']] as $key=>$spec){
            $result[$key]=[];if(!$this->exists($spec[0]))continue;
            foreach($this->all('SELECT x.routing_step_id,e.name FROM '.$spec[0].' x JOIN '.$spec[2].' e ON e.id=x.'.$spec[1].' WHERE x.routing_step_id IN ('.$in.') ORDER BY e.name',$ids) as $r)$result[$key][$r['routing_step_id']][]=$r['name'];
        }
        return $result;
    }
    public function documents($id) {return $this->exists('erp_product_documents')?$this->all('SELECT * FROM erp_product_documents WHERE entity_type="finished_product" AND entity_id=? ORDER BY CASE WHEN document_type="production_main" THEN 0 ELSE 1 END,id DESC',[$id]):[];}
    public function activeRouting($id) {return $this->exists('erp_article_routings')?$this->row('SELECT v.*,r.name routing_name FROM erp_article_routing_versions v JOIN erp_article_routings r ON r.id=v.routing_id WHERE r.finished_product_id=? AND v.status="active" AND (v.effective_from IS NULL OR v.effective_from<=date("now")) ORDER BY v.effective_from DESC,v.version_no DESC LIMIT 1',[$id]):null;}
    public function technicalVersions($id) {return $this->exists('erp_article_technical_sheet_versions')?$this->all('SELECT id,version_no,status,effective_from,created_at FROM erp_article_technical_sheet_versions WHERE finished_product_id=? ORDER BY version_no DESC',[$id]):[];}
    public function technicalVersion($id,$version) {return $this->exists('erp_article_technical_sheet_versions')?$this->row('SELECT * FROM erp_article_technical_sheet_versions WHERE finished_product_id=? AND id=?',[$id,$version]):null;}
    public function movements($id,array $filters,$page=1) {
        $empty=['rows'=>[],'total'=>0,'page'=>1,'pages'=>1];if(!$this->exists('erp_stock_movements'))return $empty;
        $where='item_type="finished_product" AND item_id=?';$params=[$id];
        if($filters['from']!==''){$where.=' AND movement_date>=?';$params[]=$filters['from'];}
        if($filters['to']!==''){$where.=' AND movement_date<?';$params[]=(new DateTime($filters['to']))->modify('+1 day')->format('Y-m-d');}
        $total=(int)$this->row('SELECT COUNT(*) total FROM erp_stock_movements WHERE '.$where,$params)['total'];$pages=max(1,(int)ceil($total/20));$page=min($pages,max(1,(int)$page));
        $rows=$this->all('SELECT * FROM erp_stock_movements WHERE '.$where.' ORDER BY movement_date DESC,id DESC LIMIT 20 OFFSET '.(($page-1)*20),$params);
        return compact('rows','total','page','pages');
    }
    /** Reverse trace only by a proven stock-unit identifier, never by free-text lot names. */
    public function reverseTrace($id,$type,$unit) {
        if(!in_array($type,['INK','ROLL'],true)||$unit<1)return [];
        if($type==='ROLL'){
            if(!$this->exists('erp_raw_material_roll_consumptions'))return [];
            $relation='erp_raw_material_roll_consumptions';$column='source_label_id';$params=[$unit,$unit,$id];$extra='';
        }else{
            if(!$this->exists('erp_production_consumptions'))return [];
            $relation='erp_production_consumptions';$column='stock_unit_id';$params=[$unit,$unit,$id];$extra=' AND c.stock_unit_type="INK"';
        }
        $proofExtra=$type==='INK'?' AND proof.stock_unit_type="INK"':'';
        return $this->all('SELECT DISTINCT o.id,o.order_number,a.id article_id,a.code,a.description FROM '.$relation.' c JOIN erp_production_orders o ON o.id=c.production_order_id LEFT JOIN erp_finished_products a ON a.id=o.finished_product_id WHERE c.'.$column.'=?'.$extra.' AND EXISTS(SELECT 1 FROM '.$relation.' proof JOIN erp_production_orders po ON po.id=proof.production_order_id WHERE proof.'.$column.'=? AND po.finished_product_id=?'.$proofExtra.') ORDER BY o.id DESC LIMIT 201',$params);
    }
    public function materials($id) {return $this->exists('erp_article_materials')?$this->all('SELECT b.*,r.code,r.description,u.code unit FROM erp_article_materials b LEFT JOIN erp_raw_materials r ON r.id=b.raw_material_id LEFT JOIN erp_units u ON u.id=r.primary_unit_id WHERE b.finished_product_id=? ORDER BY r.code',[$id]):[];}
    public function colors($id) {return $this->exists('erp_product_colors')?$this->all('SELECT pc.*,c.name color_name FROM erp_product_colors pc LEFT JOIN erp_colors c ON c.id=pc.color_id WHERE pc.finished_product_id=? ORDER BY pc.face,pc.color_order',[$id]):[];}
    public function features($id) {return $this->exists('erp_product_features')?$this->all('SELECT feature_key,feature_value FROM erp_product_features WHERE finished_product_id=? ORDER BY id',[$id]):[];}
    public function latestSheet($id) {return $this->exists('erp_technical_sheets')?$this->row('SELECT id,created_at FROM erp_technical_sheets WHERE finished_product_id=? ORDER BY created_at DESC,id DESC LIMIT 1',[$id]):null;}
}
