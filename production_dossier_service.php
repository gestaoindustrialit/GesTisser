<?php
declare(strict_types=1);


if (!class_exists('ProductionDossierService', false)) {
final class ProductionDossierService
{
    private $pdo;
    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    public function createSnapshot(int $orderId, int $articleId, int $userId, array $orderData = []): int
    {
        $article = $this->row('SELECT fp.*,c.code customer_code,c.name customer_name FROM erp_finished_products fp LEFT JOIN erp_customers c ON c.id=fp.customer_id WHERE fp.id=?',[$articleId]);
        if (!$article) throw new RuntimeException('Artigo não encontrado para gerar o dossier.');
        $version = $this->row('SELECT * FROM erp_article_technical_sheet_versions WHERE finished_product_id=? AND status="approved" AND (effective_from IS NULL OR effective_from<=date("now")) ORDER BY version_no DESC LIMIT 1',[$articleId]);
        if (!$version) {
            $next=(int)$this->scalar('SELECT COALESCE(MAX(version_no),0)+1 FROM erp_article_technical_sheet_versions WHERE finished_product_id=?',[$articleId]);
            $master=$this->articleSnapshot($articleId,$article);
            $this->pdo->prepare('INSERT INTO erp_article_technical_sheet_versions(finished_product_id,version_no,status,effective_from,snapshot_json,created_by,approved_by,approved_at) VALUES (?,?,"approved",date("now"),?,?,?,CURRENT_TIMESTAMP)')->execute([$articleId,$next,json_encode($master,JSON_UNESCAPED_UNICODE),$userId,$userId]);
            $version=['id'=>(int)$this->pdo->lastInsertId(),'version_no'=>$next,'snapshot_json'=>json_encode($master,JSON_UNESCAPED_UNICODE)];
        }
        $snapshot=json_decode((string)$version['snapshot_json'],true) ?: [];
        $snapshot['_order']=$orderData;
        $snapshot['_order']['id']=$orderId;
        $snapshot['_technical_sheet_version']=(int)$version['version_no'];
        $this->pdo->prepare('INSERT OR IGNORE INTO erp_production_order_snapshots(production_order_id,technical_sheet_version_id,snapshot_json,created_by) VALUES (?,?,?,?)')->execute([$orderId,(int)$version['id'],json_encode($snapshot,JSON_UNESCAPED_UNICODE),$userId]);
        $snapshotId=(int)$this->scalar('SELECT id FROM erp_production_order_snapshots WHERE production_order_id=?',[$orderId]);
        $token=bin2hex(random_bytes(24));
        $this->pdo->prepare('UPDATE erp_production_orders SET technical_sheet_version_id=?,snapshot_id=?,public_token=COALESCE(public_token,?) WHERE id=?')->execute([(int)$version['id'],$snapshotId,$token,$orderId]);
        return $snapshotId;
    }

    public function articleSnapshot(int $articleId, array $article = []): array
    {
        if (!$article) $article=$this->row('SELECT fp.*,c.code customer_code,c.name customer_name FROM erp_finished_products fp LEFT JOIN erp_customers c ON c.id=fp.customer_id WHERE fp.id=?',[$articleId]);
        $article['_materials']=$this->all('SELECT am.raw_material_id,rm.code,rm.description,rm.product_category type,u.code unit_code,am.quantity_per_unit,am.waste_percent,rm.standard_price FROM erp_article_materials am JOIN erp_raw_materials rm ON rm.id=am.raw_material_id LEFT JOIN erp_units u ON u.id=rm.primary_unit_id WHERE am.finished_product_id=? ORDER BY rm.code',[$articleId]);
        $article['_colors']=$this->all('SELECT pc.color_order,pc.face,COALESCE(c.name,pc.ink_type) color,pc.pantone,pc.planned_quantity,i.code ink_code,i.description ink_description FROM erp_product_colors pc LEFT JOIN erp_colors c ON c.id=pc.color_id LEFT JOIN erp_inks i ON i.pantone=pc.pantone WHERE pc.finished_product_id=? ORDER BY pc.face,pc.color_order',[$articleId]);
        $article['_documents']=$this->all('SELECT id,title,file_url,document_type,version FROM erp_product_documents WHERE entity_type="finished_product" AND entity_id=? AND status="Ativo" ORDER BY CASE WHEN document_type="production_main" THEN 0 ELSE 1 END,id',[$articleId]);
        return $article;
    }

    public function dossier(int $orderId): array
    {
        $order=$this->row('SELECT o.*,c.code customer_code,c.name customer_name,fp.code article_code,fp.description article_description,ts.version_no technical_version FROM erp_production_orders o LEFT JOIN erp_customers c ON c.id=o.customer_id LEFT JOIN erp_finished_products fp ON fp.id=o.finished_product_id LEFT JOIN erp_article_technical_sheet_versions ts ON ts.id=o.technical_sheet_version_id WHERE o.id=?',[$orderId]);
        if (!$order) throw new RuntimeException('Ordem de Fabrico não encontrada.');
        $snap=$this->row('SELECT snapshot_json FROM erp_production_order_snapshots WHERE production_order_id=?',[$orderId]);
        if (!$snap) $snap=$this->row('SELECT snapshot_json FROM erp_technical_sheets WHERE production_order_id=?',[$orderId]);
        $snapshot=$snap ? (json_decode((string)$snap['snapshot_json'],true) ?: []) : [];
        $operations=$this->all('SELECT opo.*,COALESCE(opo.operation_code,op.code) code,COALESCE(opo.operation_name,op.name) name,COALESCE(m.name,pm.name) machine_name,COALESCE(SUM(te.quantity_good),0) quantity_good,COALESCE(SUM(te.quantity_rejected),0) quantity_rejected,MIN(te.started_at) started_at,MAX(te.ended_at) ended_at,COALESCE(SUM((julianday(COALESCE(te.ended_at,CURRENT_TIMESTAMP))-julianday(te.started_at))*1440-te.pause_seconds/60.0),0) actual_minutes FROM erp_production_order_operations opo JOIN erp_operations op ON op.id=opo.operation_id LEFT JOIN erp_operation_time_entries te ON te.production_order_operation_id=opo.id LEFT JOIN erp_machines m ON m.id=te.selected_machine_id LEFT JOIN erp_machines pm ON pm.id=opo.primary_machine_id WHERE opo.production_order_id=? GROUP BY opo.id ORDER BY opo.sequence_no,opo.id',[$orderId]);
        $consumptions=$this->all('SELECT pc.*,COALESCE(rm.code,p.code) code,COALESCE(rm.description,p.description) description,u.code unit_code FROM erp_production_consumptions pc LEFT JOIN erp_raw_materials rm ON rm.id=pc.raw_material_id LEFT JOIN erp_products p ON p.id=pc.product_id LEFT JOIN erp_units u ON u.id=rm.primary_unit_id WHERE pc.production_order_id=? ORDER BY pc.id',[$orderId]);
        $costs=$this->calculateCosts($order,$snapshot,$operations,$consumptions);
        $good=0.0;$rejected=0.0;$minutes=0.0;foreach($operations as $op){$good=max($good,(float)$op['quantity_good']);$rejected+=(float)$op['quantity_rejected'];$minutes+=(float)$op['actual_minutes'];}
        $planned=(float)$order['planned_quantity'];$metrics=['good'=>$good,'rejected'=>$rejected,'missing'=>max(0,$planned-$good),'excess'=>max(0,$good-$planned),'efficiency'=>$planned>0?100*$good/$planned:0,'waste_percent'=>($good+$rejected)>0?100*$rejected/($good+$rejected):0,'actual_minutes'=>$minutes,'planned_minutes'=>array_sum(array_map(function($x){return(float)$x['planned_minutes'];},$operations)),'planned_cost'=>$costs['planned_total'],'actual_cost'=>$costs['actual_total'],'unit_cost'=>$good>0?$costs['actual_total']/$good:0,'thousand_cost'=>$good>0?$costs['actual_total']/$good*1000:0];
        $closeReport=$this->closeReport($orderId,$metrics,$costs);
        return compact('order','snapshot','operations','consumptions','costs','metrics','closeReport');
    }

    public function closeReport(int $orderId,array $metrics=[],array $costs=[]): array
    {
        $defaults=['produced_quantity'=>$metrics['good']??0,'waste_kg'=>$metrics['rejected']??0,'waste_percent'=>$metrics['waste_percent']??0,'sale_unit_price'=>0,'pallet_count'=>0,'pallet_details'=>'','notes'=>''];
        foreach(['materia_prima','tintas','diluente','acelerador','retardador','outro','impressora','corte_e_cose','cliche','energia','embalagem','caixas','transporte'] as $key)$defaults['cost_'.$key]=0;
        foreach((array)($costs['rows']??[]) as $row){$category=(string)($row['category']??'');if($category==='Matérias-primas')$defaults['cost_materia_prima']=(float)$row['actual'];elseif($category==='Máquina')$defaults['cost_impressora']=(float)$row['actual'];elseif($category==='Mão de obra')$defaults['cost_corte_e_cose']=(float)$row['actual'];}
        $saved=$this->row('SELECT report_json FROM erp_production_order_close_reports WHERE production_order_id=?',[$orderId]);
        return array_merge($defaults,$saved?(json_decode((string)$saved['report_json'],true)?:[]):[]);
    }

    public function saveCloseReport(int $orderId,int $userId,array $input,string $reason=''): array
    {
        $d=$this->dossier($orderId);$old=$d['closeReport'];$clean=[];$numeric=['produced_quantity','waste_kg','waste_percent','sale_unit_price','pallet_count'];
        foreach($old as $key=>$value){if(strpos($key,'cost_')===0||in_array($key,$numeric,true))$clean[$key]=max(0,(float)str_replace(',','.',(string)($input[$key]??$value)));else$clean[$key]=trim((string)($input[$key]??$value));}
        if($clean==$old)return $old;
        $json=json_encode($clean,JSON_UNESCAPED_UNICODE);$this->pdo->beginTransaction();
        try{$exists=$this->scalar('SELECT id FROM erp_production_order_close_reports WHERE production_order_id=?',[$orderId]);if($exists)$this->pdo->prepare('UPDATE erp_production_order_close_reports SET report_json=?,updated_by=?,updated_at=CURRENT_TIMESTAMP WHERE production_order_id=?')->execute([$json,$userId,$orderId]);else$this->pdo->prepare('INSERT INTO erp_production_order_close_reports(production_order_id,report_json,updated_by) VALUES (?,?,?)')->execute([$orderId,$json,$userId]);$this->pdo->prepare('INSERT INTO erp_production_order_audit(production_order_id,user_id,action,old_value_json,new_value_json,reason) VALUES (?,? ,"update_close_report",?,?,?)')->execute([$orderId,$userId,json_encode($old,JSON_UNESCAPED_UNICODE),$json,$reason?:null]);$this->pdo->commit();}catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
        return $clean;
    }

    public function closureIssues(array $d): array
    {
        $issues=[];foreach($d['operations'] as $op){if((string)$op['status']!=='Concluída')$issues[]='Operação '.$op['code'].' — '.$op['name'].' ainda não está concluída.';if((float)$op['actual_minutes']<=0)$issues[]='Falta registo de tempo na operação '.$op['code'].'.';}
        if((float)$d['metrics']['good']<=0)$issues[]='Falta registar quantidade boa produzida.';
        if(!$d['consumptions'])$issues[]='Faltam consumos reais ou movimentos de stock associados à OF.';
        return array_values(array_unique($issues));
    }

    public function close(int $orderId,int $userId,bool $override,string $reason): array
    {
        $d=$this->dossier($orderId);$issues=$this->closureIssues($d);
        if($issues&&!$override)throw new RuntimeException("Não é possível fechar a OF:\n• ".implode("\n• ",$issues));
        if($issues&&trim($reason)==='')throw new RuntimeException('O motivo do override é obrigatório.');
        $this->pdo->prepare('INSERT INTO erp_production_order_closures(production_order_id,metrics_json,total_planned_cost,total_actual_cost,override_reason,closed_by) VALUES (?,?,?,?,?,?)')->execute([$orderId,json_encode($d['metrics'],JSON_UNESCAPED_UNICODE),(float)$d['metrics']['planned_cost'],(float)$d['metrics']['actual_cost'],$issues?$reason:null,$userId]);
        $this->pdo->prepare('UPDATE erp_production_orders SET status="Fechada",produced_quantity=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([(float)$d['metrics']['good'],$orderId]);
        $this->pdo->prepare('INSERT INTO erp_production_order_audit(production_order_id,user_id,action,new_value_json,reason) VALUES (?,? ,"close",?,?)')->execute([$orderId,$userId,json_encode($d['metrics'],JSON_UNESCAPED_UNICODE),$issues?$reason:null]);
        return $d;
    }

    private function calculateCosts(array $order,array $snapshot,array $ops,array $cons): array
    {
        $planned=[];$actual=[];
        foreach((array)($snapshot['_materials']??[]) as $m){$q=(float)($m['quantity_per_unit']??0)*(float)$order['planned_quantity']*(1+(float)($m['waste_percent']??0)/100);$planned['Matérias-primas'] = ($planned['Matérias-primas']??0)+$q*(float)($m['standard_price']??0);}
        foreach($cons as $c){$actual['Matérias-primas']=($actual['Matérias-primas']??0)+(float)$c['quantity']*(float)$c['unit_cost'];}
        foreach($ops as $op){$machineId=(int)($op['selected_machine_id']?:$op['primary_machine_id']);if($machineId>0){$rate=$this->activeRate('machine',$machineId);$planned['Máquina']=($planned['Máquina']??0)+(float)$op['planned_minutes']/60*$rate;$actual['Máquina']=($actual['Máquina']??0)+(float)$op['actual_minutes']/60*$rate;}$labour=$this->activeRate('operator',0);$planned['Mão de obra']=($planned['Mão de obra']??0)+(float)$op['planned_minutes']/60*$labour*(int)$op['operators_count'];$actual['Mão de obra']=($actual['Mão de obra']??0)+(float)$op['actual_minutes']/60*$labour*(int)$op['operators_count'];}
        $categories=array_values(array_unique(array_merge(array_keys($planned),array_keys($actual))));$rows=[];foreach($categories as $cat){$p=$planned[$cat]??0;$a=$actual[$cat]??0;$rows[]=['category'=>$cat,'planned'=>$p,'actual'=>$a,'difference'=>$a-$p];}
        return ['rows'=>$rows,'planned_total'=>array_sum($planned),'actual_total'=>array_sum($actual)];
    }
    private function activeRate(string $type,int $id): float {$sql='SELECT hourly_rate FROM erp_production_cost_rates WHERE cost_type=? AND is_active=1 AND (reference_id=? OR reference_id IS NULL) AND valid_from<=date("now") AND (valid_until IS NULL OR valid_until>=date("now")) ORDER BY CASE WHEN reference_id=? THEN 0 ELSE 1 END,valid_from DESC LIMIT 1';return(float)$this->scalar($sql,[$type,$id,$id]);}
    private function row(string $sql,array $p=[]){$s=$this->pdo->prepare($sql);$s->execute($p);return$s->fetch(PDO::FETCH_ASSOC);}
    private function all(string $sql,array $p=[]):array{$s=$this->pdo->prepare($sql);$s->execute($p);return$s->fetchAll(PDO::FETCH_ASSOC);}
    private function scalar(string $sql,array $p=[]){$s=$this->pdo->prepare($sql);$s->execute($p);return$s->fetchColumn();}
}
}
