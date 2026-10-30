<?php
require_once __DIR__.'/../../erp_migrations.php';
/** Masses are integer milligrams; no implicit volume conversion. */
class ColorimetryService
{
    private $db;
    private $user;
    public function __construct(PDO $db, array $user) { $this->db=$db; $this->user=$user; }
    public static function migrate(PDO $db)
    {
        $s=$db->query("SELECT 1 FROM sqlite_master WHERE name='erp_ink_schema_migrations'");
        if($s->fetchColumn())return;
        $db->exec('BEGIN IMMEDIATE');
        try {$db->exec(file_get_contents(__DIR__.'/../../migrations/colorimetry.sql'));$db->exec('COMMIT');}
        catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
    }
    public function can($permission='ink.prepare') { return gt_erp_user_can($this->db,$this->user,$permission); }
    public function authorize($permission='ink.prepare')
    {
        if(empty($this->user['id']) || (isset($this->user['is_active']) && !$this->user['is_active']) || !$this->can($permission))throw new RuntimeException('Sem permissão para esta operação.',403);
    }
    public static function mass($value,$unit='g')
    {
        $v=str_replace(',','.',trim((string)$value));
        if(!in_array($unit,['g','kg'],true)||!preg_match('/^\d{1,7}(?:\.\d{1,6})?$/D',$v))throw new InvalidArgumentException('Indique uma massa válida em g ou kg.');
        $mg=(float)$v*($unit==='kg'?1000000:1000);
        if($mg<1||$mg>1000000000||abs($mg-round($mg))>.00001)throw new InvalidArgumentException('A massa deve estar entre 0,001 g e 1000 kg, com precisão de 1 mg.');
        return (int)round($mg);
    }
    public static function grams($mg) { return number_format($mg/1000,3,'.',''); }
    private function one($sql,array $args=[])
    { $s=$this->db->prepare($sql);$s->execute($args);$r=$s->fetch(PDO::FETCH_ASSOC);if(!$r)throw new InvalidArgumentException('Registo não encontrado.');return $r; }
    public function query($sql,array $args=[]) { $this->authorize();$s=$this->db->prepare($sql);$s->execute($args);return $s->fetchAll(PDO::FETCH_ASSOC); }
    public function formula($id) { $this->authorize();return $this->one('SELECT f.*,c.code,c.name,c.pantone FROM erp_ink_formulas f JOIN erp_colors c ON c.id=f.color_id WHERE f.id=?',[$id]); }
    public function container($id) { $this->authorize();return $this->one('SELECT b.*,m.code mix_code,m.formula_id,m.created_at prepared_at,u.name operator,c.code color_code,c.name color_name,c.pantone,f.version,f.color_id FROM erp_ink_containers b JOIN erp_ink_mixes m ON m.id=b.mix_id JOIN erp_ink_formulas f ON f.id=m.formula_id JOIN erp_colors c ON c.id=f.color_id JOIN users u ON u.id=m.created_by WHERE b.id=?',[$id]); }
    public static function scale(array $components,$target)
    {
        $total=array_sum(array_column($components,'mg'));if($total<=0||$target<=0)throw new InvalidArgumentException('Composição vazia.');
        $result=[];$used=0;$cumulative=0;
        foreach($components as $c){$cumulative+=(int)$c['mg'];$next=(int)round($target*($cumulative/$total));$c['mg']=$next-$used;if($c['mg']<=0)throw new InvalidArgumentException('Quantidade insuficiente para a precisão de 1 mg.');$used=$next;$result[]=$c;}
        return $result;
    }
    private function components(array $input)
    {
        $out=[];$seen=[];
        foreach($input as $row){
            if(empty($row['material_id']) && empty($row['quantity']))continue;
            $id=(int)$row['material_id'];if(isset($seen[$id]))throw new InvalidArgumentException('Componente repetido.');$seen[$id]=true;
            $m=$this->one('SELECT r.id,r.code,r.description,u.code unit FROM erp_raw_materials r LEFT JOIN erp_units u ON u.id=r.primary_unit_id WHERE r.id=? AND r.status="Ativo"',[$id]);
            if(!in_array(strtolower((string)$m['unit']),['g','kg'],true))throw new InvalidArgumentException('O componente deve ter unidade de massa g ou kg no ERP.');
            $out[]=['source_label_id'=>(int)($row['source_label_id']??0),'source_balance_id'=>(int)($row['source_balance_id']??0),'material_id'=>$id,'code'=>$m['code'],'name'=>$m['description'],'mg'=>self::mass($row['quantity'],$row['unit']??'g')];
        }
        if(!$out||count($out)>100)throw new InvalidArgumentException('Registe entre 1 e 100 componentes.');return $out;
    }
    private function event($action,$entity,$id,array $payload=[],$container=null,$delta=0,$order=null)
    {
        $this->db->prepare('INSERT INTO erp_ink_events(action,entity,entity_id,payload_json,user_id,container_id,delta_mg,production_order_id) VALUES (?,?,?,?,?,?,?,?)')->execute([$action,$entity,$id,json_encode($payload,JSON_UNESCAPED_UNICODE),(int)$this->user['id'],$container,$delta,$order]);
        return (int)$this->db->lastInsertId();
    }
    /** All writes, including retries, run under one SQLite writer lock. */
    public function execute($action,array $data,$key)
    {
        $this->authorize();
        if(!preg_match('/^[a-f0-9]{32,64}$/D',(string)$key))throw new InvalidArgumentException('Identificador de pedido inválido.');
        $permission=in_array($action,['color','formula','propose','attach'],true)?'ink.formula.create':($action==='formula_state'?'ink.formula.approve':'ink.prepare');
        $this->authorize($permission);
        if(in_array($action,['container_state'],true)&&empty($this->user['is_admin']))throw new RuntimeException('Acesso reservado a administradores.',403);
        $fingerprint=hash('sha256',$action.json_encode($data));$uid=(int)$this->user['id'];
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $s=$this->db->prepare('SELECT * FROM erp_ink_requests WHERE user_id=? AND request_key=?');$s->execute([$uid,$key]);$old=$s->fetch(PDO::FETCH_ASSOC);
            if($old){if(!hash_equals($old['fingerprint'],$fingerprint))throw new RuntimeException('Pedido repetido com dados diferentes.');$this->db->exec('COMMIT');return json_decode($old['result_json'],true);}
            $result=$this->write($action,$data);
            $this->db->prepare('INSERT INTO erp_ink_requests(user_id,request_key,fingerprint,result_json) VALUES (?,?,?,?)')->execute([$uid,$key,$fingerprint,json_encode($result)]);
            $this->db->exec('COMMIT');return $result;
        }catch(Throwable $e){$this->db->exec('ROLLBACK');throw $e;}
    }
    private function write($action,array $d)
    {
        $uid=(int)$this->user['id'];
        if($action==='color'){
            $code=trim($d['code']??'');$name=trim($d['name']??'');$hex=trim($d['hex']??'');
            if($code===''||$name===''||strlen($code)>80||strlen($name)>200||($hex!==''&&!preg_match('/^#[a-fA-F0-9]{6}$/D',$hex)))throw new InvalidArgumentException('Código, nome ou HEX inválido.');
            $id=(int)($d['id']??0);
            if($id){$this->one('SELECT id FROM erp_colors WHERE id=?',[$id]);$this->db->prepare('UPDATE erp_colors SET code=?,name=?,pantone=? WHERE id=?')->execute([$code,$name,trim($d['pantone']??''),$id]);}
            else{$this->db->prepare('INSERT INTO erp_colors(code,name,pantone) VALUES (?,?,?)')->execute([$code,$name,trim($d['pantone']??'')]);$id=(int)$this->db->lastInsertId();}
            $status=$d['status']??'Em desenvolvimento';if(!in_array($status,['Em desenvolvimento','Aprovada','Inativa'],true))throw new InvalidArgumentException('Estado inválido.');
            if($status==='Aprovada')$this->authorize('ink.formula.approve');
            $this->db->prepare('INSERT OR IGNORE INTO erp_ink_color_details(color_id,created_by) VALUES (?,?)')->execute([$id,$uid]);
            $this->db->prepare('UPDATE erp_ink_color_details SET customer_id=?,article_id=?,ink_type_id=?,substrate=?,finish=?,hex=?,notes=?,status=? WHERE color_id=?')->execute([($d['customer_id']??'')?:null,($d['article_id']??'')?:null,($d['ink_type_id']??'')?:null,$d['substrate']??'',$d['finish']??'',$hex,$d['notes']??'',$status,$id]);
            $this->event('cor_guardada','color',$id,$d);return ['color_id'=>$id];
        }
        if($action==='attach'){
            $f=$this->formula((int)$d['formula_id']);if($f['status']!=='Em desenvolvimento'||$f['approved_at']!==null)throw new InvalidArgumentException('Anexe referências ao rascunho antes da aprovação.');
            require_once __DIR__.'/UploadService.php';
            $content=base64_decode($d['content']??'',true);if($content===false||strlen($content)<1||strlen($content)>5242880)throw new InvalidArgumentException('Anexo inválido (máximo 5 MB).');
            $tmp=tempnam(sys_get_temp_dir(),'ink-mime-');try{file_put_contents($tmp,$content);$mime=UploadService::detectMime($tmp);}finally{unlink($tmp);}
            if(!in_array($mime,['application/pdf','image/png','image/jpeg'],true))throw new InvalidArgumentException('Apenas PDF, PNG ou JPEG.');
            $name=substr(basename($d['name']??'referencia'),0,180);$s=$this->db->prepare('INSERT INTO erp_ink_formula_attachments(formula_id,name,mime,content,created_by) VALUES (?,?,?,?,?)');$s->bindValue(1,$f['id'],PDO::PARAM_INT);$s->bindValue(2,$name);$s->bindValue(3,$mime);$s->bindValue(4,$content,PDO::PARAM_LOB);$s->bindValue(5,$uid,PDO::PARAM_INT);$s->execute();
            $this->event('anexo','formula',$f['id'],['attachment_id'=>(int)$this->db->lastInsertId(),'name'=>$name,'sha256'=>hash('sha256',$content)]);return ['formula_id'=>(int)$f['id']];
        }
        if($action==='formula'||$action==='propose'){
            if($action==='propose'){
                $b=$this->container((int)$d['container_id']);$components=json_decode($b['composition_json'],true);$d['color_id']=$b['color_id'];$d['parent_id']=$b['formula_id'];
            }else $components=$this->components((array)($d['components']??[]));
            $this->one('SELECT id FROM erp_colors WHERE id=?',[(int)$d['color_id']]);
            $family='F-'.strtoupper(bin2hex(random_bytes(6)));$version=1;
            if(!empty($d['parent_id'])){$parent=$this->formula((int)$d['parent_id']);if((int)$parent['color_id']!==(int)$d['color_id'])throw new InvalidArgumentException('A versão deve pertencer à mesma cor.');$family=$parent['family'];$s=$this->db->prepare('SELECT MAX(version) FROM erp_ink_formulas WHERE family=?');$s->execute([$family]);$version=(int)$s->fetchColumn()+1;}
            $id=(int)($d['id']??0);
            if($id){$f=$this->formula($id);if($f['approved_at']!==null||$f['status']!=='Em desenvolvimento')throw new InvalidArgumentException('Crie uma nova versão da fórmula.');
                $this->db->prepare('UPDATE erp_ink_formulas SET components_json=?,notes=?,substrate=?,ink_type_id=? WHERE id=?')->execute([json_encode($components),$d['notes']??'',$d['substrate']??'',($d['ink_type_id']??'')?:null,$id]);
            }else{
                $this->db->prepare('INSERT INTO erp_ink_formulas(color_id,family,version,components_json,notes,substrate,ink_type_id,created_by) VALUES (?,?,?,?,?,?,?,?)')->execute([(int)$d['color_id'],$family,$version,json_encode($components),$d['notes']??'',$d['substrate']??'',($d['ink_type_id']??'')?:null,$uid]);$id=(int)$this->db->lastInsertId();
            }
            $this->event('formula_rascunho','formula',$id,$d);return ['formula_id'=>$id];
        }
        if($action==='formula_state'){
            $f=$this->formula((int)$d['id']);$status=$d['status']??'';if(!in_array($status,['Aprovada','Inativa'],true)||($status==='Aprovada'&&$f['status']!=='Em desenvolvimento'))throw new InvalidArgumentException('Transição de fórmula inválida.');
            $this->db->prepare('UPDATE erp_ink_formulas SET status=?,approved_by=CASE WHEN ?="Aprovada" THEN ? ELSE approved_by END,approved_at=CASE WHEN ?="Aprovada" THEN CURRENT_TIMESTAMP ELSE approved_at END WHERE id=?')->execute([$status,$status,$uid,$status,$f['id']]);
            $this->event('formula_estado','formula',$f['id'],['status'=>$status]);return ['formula_id'=>(int)$f['id']];
        }
        if($action==='prepare'){
            $f=$this->formula((int)$d['formula_id']);$s=$this->db->prepare('SELECT status FROM erp_ink_color_details WHERE color_id=?');$s->execute([$f['color_id']]);if($s->fetchColumn()==='Inativa')throw new InvalidArgumentException('A cor está inativa.');
            if($f['status']!=='Aprovada'&& !($f['status']==='Em desenvolvimento'&&$this->can('ink.formula.create')))throw new InvalidArgumentException('Selecione uma fórmula aprovada.');
            $planned=self::mass($d['quantity'],$d['unit']??'g');$actual=$this->components((array)($d['components']??[]));
            $expected=array_column(json_decode($f['components_json'],true),'material_id');$used=array_column($actual,'material_id');sort($expected);sort($used);if($used!==$expected)throw new InvalidArgumentException('Confirme a pesagem real de todos os componentes da fórmula.');
            $total=array_sum(array_column($actual,'mg'));if($total>1000000000)throw new InvalidArgumentException('Massa máxima de preparação: 1000 kg.');$parts=[];
            foreach((array)($d['containers']??[]) as $p){if(empty($p['quantity']))continue;$parts[]=['mg'=>self::mass($p['quantity'],$p['unit']??'g'),'lot'=>trim($p['lot']??''),'location'=>trim($p['location']??''),'warehouse_id'=>(int)($p['warehouse_id']??0)];}
            if(!$parts||array_sum(array_column($parts,'mg'))!==$total)throw new InvalidArgumentException('A soma dos recipientes deve corresponder exatamente às pesagens reais.');
            $this->deductComponents($actual);
            $code='MIX-'.date('Y').'-'.strtoupper(bin2hex(random_bytes(6)));
            $snapshot=['formula'=>$f,'actual'=>$actual,'expected'=>self::scale(json_decode($f['components_json'],true),$planned)];
            $this->db->prepare('INSERT INTO erp_ink_mixes(code,formula_id,snapshot_json,planned_mg,actual_mg,notes,created_by) VALUES (?,?,?,?,?,?,?)')->execute([$code,$f['id'],json_encode($snapshot),$planned,$total,$d['notes']??'',$uid]);$mix=(int)$this->db->lastInsertId();$ids=[];
            $remainingComposition=$actual;$remainingTotal=$total;
            foreach($parts as $p){if(!$p['warehouse_id']||$p['lot']==='')throw new InvalidArgumentException('Indique armazém e lote de cada recipiente.');
                $composition=self::scale($remainingComposition,$p['mg']);
                foreach($composition as $i=>$part){$remainingComposition[$i]['mg']-=$part['mg'];}
                $remainingComposition=array_values(array_filter($remainingComposition,function($c){return $c['mg']>0;}));
                $remainingTotal-=$p['mg'];$barcode='TIN-'.strtoupper(bin2hex(random_bytes(8)));
                $this->db->prepare('INSERT INTO erp_ink_containers(code,mix_id,lot,initial_mg,available_mg,composition_json,location,warehouse_id) VALUES (?,?,?,?,?,?,?,?)')->execute([$barcode,$mix,$p['lot'],$p['mg'],$p['mg'],json_encode($composition),$p['location'],$p['warehouse_id']]);$id=(int)$this->db->lastInsertId();$ids[]=$id;
                $this->balance($id,$p['mg'],'Bloqueado');$this->event('entrada','mix',$mix,['composition'=>$composition],$id,$p['mg']);
            }
            $this->event('preparacao','mix',$mix,$snapshot);return ['mix_id'=>$mix,'containers'=>$ids];
        }
        if(in_array($action,['correct','consume','return','loss','adjust','result','container_state','print'],true)){
            $b=$this->container((int)$d['container_id']);$id=(int)$b['id'];$available=(int)$b['available_mg'];$composition=json_decode($b['composition_json'],true);$delta=0;$order=null;
            if($action==='correct'){
                if($available<=0||$b['status']==='Reservado'||$b['status']==='Em utilização')throw new InvalidArgumentException('Recipiente indisponível para correção.');
                $added=$this->components((array)($d['components']??[]));$this->deductComponents($added);$composition=self::scale($composition,$available);
                foreach($added as $a){$found=false;foreach($composition as &$c){if($c['material_id']===$a['material_id']){$c['mg']+=$a['mg'];$found=true;break;}}unset($c);if(!$found)$composition[]=$a;$delta+=$a['mg'];}
                if(trim($d['notes']??'')==='')throw new InvalidArgumentException('Indique o motivo da correção.');$b['status']='Bloqueado';$d['added']=$added;
            }elseif(in_array($action,['consume','return','loss','adjust'],true)){
                if(trim($d['notes']??'')==='')throw new InvalidArgumentException('Indique o motivo / referência.');
                if($b['status']==='Bloqueado')throw new InvalidArgumentException('Recipiente bloqueado.');
                $amount=self::mass($d['quantity'],$d['unit']??'g');$returnComposition=null;$delta=in_array($action,['return'],true)||($action==='adjust'&&($d['direction']??'')==='in')?$amount:-$amount;
                if($action==='adjust'&&empty($this->user['is_admin']))throw new RuntimeException('Acertos reservados a administradores.',403);
                if(!empty($d['production_order_id'])){$order=(int)$d['production_order_id'];$of=$this->one('SELECT id,product_id FROM erp_production_orders WHERE id=?',[$order]);}
                if($action==='return'){
                    $origin=$this->one('SELECT * FROM erp_ink_events WHERE id=? AND container_id=? AND action="consume"',[(int)($d['consumption_event_id']??0),$id]);
                    if($order!==($origin['production_order_id']===null?null:(int)$origin['production_order_id']))throw new InvalidArgumentException('A OF da devolução deve coincidir com o consumo.');
                    $originPayload=json_decode($origin['payload_json'],true);$returnComposition=$originPayload['removed_composition'];
                    $previous=$this->db->prepare('SELECT payload_json FROM erp_ink_events WHERE container_id=? AND action="return"');$previous->execute([$id]);
                    foreach($previous->fetchAll(PDO::FETCH_ASSOC) as $prior){$payload=json_decode($prior['payload_json'],true);if((int)$payload['consumption_event_id']!==(int)$origin['id'])continue;foreach($payload['returned_composition'] as $r){foreach($returnComposition as &$c){if($c['material_id']===$r['material_id'])$c['mg']-=$r['mg'];}unset($c);}}
                    $returnComposition=array_values(array_filter($returnComposition,function($c){return $c['mg']>0;}));
                    if($amount>array_sum(array_column($returnComposition,'mg')))throw new InvalidArgumentException('Devolução superior ao consumo ainda não devolvido.');
                    $returnComposition=self::scale($returnComposition,$amount);$d['returned_composition']=$returnComposition;

                }
                if($available+$delta<0)throw new InvalidArgumentException('Stock insuficiente.');
                if($available+$delta>1000000000)throw new InvalidArgumentException('Massa máxima excedida.');
                if($available>0)$composition=self::scale($composition,$available);
                if($action==='return'){
                    if($available===0)$composition=[];
                    foreach($returnComposition as $r){$found=false;foreach($composition as &$c){if($c['material_id']===$r['material_id']){$c['mg']+=$r['mg'];$found=true;break;}}unset($c);if(!$found)$composition[]=$r;}
                }elseif($available+$delta>0){
                    $newComposition=self::scale($composition,$available+$delta);
                    if($action==='consume'){$removed=$composition;foreach($removed as $i=>&$c)$c['mg']-=$newComposition[$i]['mg'];unset($c);$d['removed_composition']=array_values(array_filter($removed,function($c){return $c['mg']>0;}));}
                    $composition=$newComposition;
                }elseif($action==='consume')$d['removed_composition']=$composition;
                $b['status']=$available+$delta===0?'Esgotado':'Disponível';
                if($order&&in_array($action,['consume','return'],true)){
                    $this->db->prepare('INSERT INTO erp_production_consumptions(production_order_id,product_id,quantity,created_by,consumption_type,stock_unit_type,stock_unit_id,quantity_before,quantity_after,unit_code,completed_at) VALUES (?,?,?,?,"PREPARED_INK","PREPARED_INK",?,?,?,"kg",CURRENT_TIMESTAMP)')->execute([$order,$of['product_id'],-$delta/1000000,$uid,$id,$available/1000000,($available+$delta)/1000000]);
                }
            }elseif($action==='result'){
                if(!in_array($d['result']??'',['Aprovado','Rejeitado'],true))throw new InvalidArgumentException('Resultado inválido.');
                if($available===0)throw new InvalidArgumentException('Recipiente esgotado.');$b['status']=$d['result']==='Aprovado'?'Disponível':'Bloqueado';
            }elseif($action==='container_state'){
                if(!in_array($d['status']??'',['Disponível','Reservado','Em utilização','Bloqueado'],true)||trim($d['notes']??'')==='')throw new InvalidArgumentException('Indique estado e justificação.');$b['status']=$d['status'];
            }elseif($action==='print'){
                // Printing audit only; stock and identity are unchanged.
                return ['event_id'=>$this->event('print','container',$id,$d,$id)];
            }
            $this->db->prepare('UPDATE erp_ink_containers SET available_mg=?,composition_json=?,status=? WHERE id=?')->execute([$available+$delta,json_encode($composition),$b['status'],$id]);
            if($available+$delta>1000000000)throw new InvalidArgumentException('Massa máxima excedida.');
            $this->balance($id,$available+$delta,$b['status']);$event=$this->event($action,'container',$id,array_merge($d,['composition'=>$composition]),$id,$delta,$order);
            return ['container_id'=>$id,'event_id'=>$event];
        }
        throw new InvalidArgumentException('Operação desconhecida.');
    }
    private function deductComponents(array &$components)
    {
        foreach($components as &$c){
            if(empty($c['source_balance_id']))throw new InvalidArgumentException('Selecione o stock de origem de cada componente pesado.');
            $b=$this->one('SELECT b.rowid balance_id,b.*,u.code unit,r.average_price FROM erp_stock_balances b JOIN erp_raw_materials r ON r.id=b.item_id JOIN erp_units u ON u.id=r.primary_unit_id WHERE b.rowid=? AND b.item_type="raw_material" AND b.item_id=?',[$c['source_balance_id'],$c['material_id']]);
            $quantity=$c['mg']/(strtolower($b['unit'])==='kg'?1000000:1000);
            if($quantity>(float)$b['physical_qty']-(float)$b['reserved_qty']-(float)$b['blocked_qty']+.000000001)throw new InvalidArgumentException('Stock insuficiente do componente '.$c['code'].'.');
            if($this->db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='erp_raw_material_ink_labels'")->fetchColumn()){
                $labels=$this->db->prepare('SELECT * FROM erp_raw_material_ink_labels WHERE raw_material_id=? AND COALESCE(warehouse_id,0)=CAST(? AS INTEGER) AND COALESCE(supplier_lot,"")=? AND weight_kg>0');$labels->execute([$c['material_id'],(int)$b['warehouse_id'],(string)$b['lot']]);$matching=$labels->fetchAll(PDO::FETCH_ASSOC);
                $label=null;foreach($matching as $candidate)if((int)$candidate['id']===(int)$c['source_label_id'])$label=$candidate;
                if($matching&&!$label)throw new InvalidArgumentException('Selecione também a lata de origem do componente '.$c['code'].'.');
                if(!empty($c['source_label_id'])&&!$label)throw new InvalidArgumentException('A lata selecionada não corresponde ao stock de origem.');
                if($label){
                    $remaining=(float)$label['weight_kg']-$c['mg']/1000000;
                    if(in_array($label['status'],['IN_USE','BLOCKED','CONSUMED'],true)||$remaining<-.000000001)throw new InvalidArgumentException('Lata de origem indisponível ou com peso insuficiente.');
                    $remaining=max(0,$remaining);$this->db->prepare('UPDATE erp_raw_material_ink_labels SET weight_kg=?,status=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$remaining,$remaining>0?'PARTIAL':'CONSUMED',$label['id']]);
                }
            }
            $this->db->prepare('UPDATE erp_stock_balances SET physical_qty=physical_qty-?,updated_at=CURRENT_TIMESTAMP WHERE rowid=?')->execute([$quantity,$b['balance_id']]);
            $this->db->prepare('INSERT INTO erp_stock_movements(movement_number,movement_type,item_type,item_id,lot,quantity,weight,warehouse_from_id,location_from_id,unit_cost,total_cost,source_type,reason,notes,created_by) VALUES (?,"Saída","raw_material",?,?,?,?,?,?,?,?,"ink_preparation","Preparação de tinta",?,?)')->execute(['TINTA-'.bin2hex(random_bytes(12)),$c['material_id'],$b['lot'],$quantity,$c['mg']/1000000,($b['warehouse_id']?:null),($b['location_id']?:null),$b['average_price'],$quantity*(float)$b['average_price'],'Componente pesado: '.$c['code'],(int)$this->user['id']]);
            $c['source_lot']=$b['lot'];$c['source_warehouse_id']=$b['warehouse_id'];$c['stock_movement_id']=(int)$this->db->lastInsertId();
        }
    }
    private function balance($id,$mg,$status)
    {
        $b=$this->one('SELECT warehouse_id,lot FROM erp_ink_containers WHERE id=?',[$id]);
        // One projection per physical container; material components remain separate.
        $s=$this->db->prepare('SELECT 1 FROM erp_stock_balances WHERE item_type="prepared_ink" AND item_id=? AND warehouse_id=? AND location_id=0 AND lot=?');$s->execute([$id,$b['warehouse_id'],$b['lot']]);
        if(!$s->fetchColumn())$this->db->prepare('INSERT INTO erp_stock_balances(item_type,item_id,warehouse_id,location_id,lot) VALUES ("prepared_ink",?,?,0,?)')->execute([$id,$b['warehouse_id'],$b['lot']]);
        $this->db->prepare('UPDATE erp_stock_balances SET physical_qty=?,reserved_qty=?,blocked_qty=?,updated_at=CURRENT_TIMESTAMP WHERE item_type="prepared_ink" AND item_id=?')->execute([$mg/1000000,$status==='Reservado'?$mg/1000000:0,$status==='Bloqueado'?$mg/1000000:0,$id]);
    }
}
