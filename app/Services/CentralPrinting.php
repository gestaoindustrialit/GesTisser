<?php
/** Central printing. Explicit migration only; PHP 7.0 / SQLite. */
class CentralPrinting
{
    private $db;
    public function __construct(PDO $db) { $this->db=$db; }
    public function installed() { return (bool)$this->db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='erp_print_config'")->fetchColumn(); }
    public function enabled() { return $this->installed() && $this->db->query('SELECT enabled FROM erp_print_config WHERE id=1')->fetchColumn()==1; }
    public function migrate($backupDirectory)
    {
        if ($this->installed()) return;
        if (!is_dir($backupDirectory) && !mkdir($backupDirectory,0700,true)) throw new RuntimeException('Não foi possível criar o backup.');
        if(file_put_contents($backupDirectory.'/.htaccess', "Require all denied\n", LOCK_EX)===false)throw new RuntimeException('Falha ao proteger backups.');
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $dump="-- GesTisser printing snapshot UTC ".gmdate('c')."\nBEGIN;\n";
            foreach (['erp_printers','erp_work_centers'] as $table) {
                $stmt=$this->db->prepare("SELECT sql FROM sqlite_master WHERE type='table' AND name=?");$stmt->execute([$table]);$ddl=$stmt->fetchColumn();
                if (!$ddl) throw new RuntimeException('Execute primeiro as migrações existentes dos centros de trabalho.');
                $dump.=$ddl.";\n";
                foreach ($this->db->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $values=[];foreach($row as $value)$values[]=$value===null?'NULL':$this->db->quote((string)$value);
                    $dump.='INSERT INTO '.$table.' VALUES ('.implode(',',$values).");\n";
                }
            }
            $path=$backupDirectory.'/printing-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.sql';
            if (file_put_contents($path,$dump."COMMIT;\n",LOCK_EX)===false) throw new RuntimeException('Backup falhou.');
            chmod($path,0600);
            $this->db->exec(file_get_contents(__DIR__.'/../../migrations/central_printing.sql'));
            $columns=array_column($this->db->query('PRAGMA table_info(erp_printers)')->fetchAll(PDO::FETCH_ASSOC),'name');
            foreach (['print_code'=>'TEXT','printer_type'=>"TEXT NOT NULL DEFAULT 'documents'",'brand'=>'TEXT','model'=>'TEXT','connection_type'=>"TEXT NOT NULL DEFAULT 'ipp'",'agent_id'=>'TEXT','windows_queue'=>'TEXT','language'=>"TEXT NOT NULL DEFAULT 'driver'",'dpi'=>'INTEGER NOT NULL DEFAULT 203','online'=>'INTEGER NOT NULL DEFAULT 0','last_seen'=>'TEXT','notes'=>'TEXT'] as $name=>$definition) {
                if (!in_array($name,$columns,true)) $this->db->exec('ALTER TABLE erp_printers ADD COLUMN '.$name.' '.$definition);
            }
            $this->db->exec("INSERT OR IGNORE INTO erp_print_types(code,name) VALUES ('document','Documento'),('label','Etiqueta')");
            $this->db->exec("INSERT OR IGNORE INTO erp_print_formats(code,name,width_mm,height_mm,orientation) VALUES ('A4','A4',210,297,'portrait'),('100x50','Etiqueta 100 × 50',100,50,'landscape'),('50x30','Etiqueta 50 × 30',50,30,'landscape'),('100x100','Etiqueta 100 × 100',100,100,'portrait')");
            // Preserve legacy associations without assuming physical label capabilities.
            $this->db->exec("INSERT OR IGNORE INTO erp_work_center_printers(work_center_id,printer_id,type_id,format_id,is_default) SELECT id,default_printer_id,(SELECT id FROM erp_print_types WHERE code='document'),(SELECT id FROM erp_print_formats WHERE code='A4'),1 FROM erp_work_centers WHERE default_printer_id IS NOT NULL AND default_printer_id IN (SELECT id FROM erp_printers)");
            $this->db->exec('COMMIT');
        } catch (Throwable $e) { $this->db->exec('ROLLBACK');throw $e; }
    }
    public function audit($actor,$event,$job=null,$detail='') {
        $this->db->prepare('INSERT INTO erp_print_audit(actor,event,job_id,detail) VALUES (?,?,?,?)')->execute([(string)$actor,$event,$job,$detail]);
    }
    public function setEnabled($enabled,$actor) {
        $this->db->prepare('UPDATE erp_print_config SET enabled=? WHERE id=1')->execute([$enabled?1:0]);$this->audit($actor,'enabled',null,$enabled?'1':'0');
    }
    public function createAgent($name,$actor) {
        if (trim($name)==='' || strlen($name)>120) throw new InvalidArgumentException('Nome inválido.');
        $token=bin2hex(random_bytes(32));$id=bin2hex(random_bytes(16));
        $this->db->prepare('INSERT INTO erp_print_agents(id,name,token_hash) VALUES (?,?,?)')->execute([$id,$name,hash('sha256',$token)]);
        $this->audit($actor,'agent_created',null,$id);return ['id'=>$id,'token'=>$token];
    }
    public function authenticate($id,$token) {
        if (!preg_match('/^[a-f0-9]{32}$/D',$id) || !preg_match('/^[a-f0-9]{64}$/D',$token)) throw new RuntimeException('Credenciais inválidas.');
        $s=$this->db->prepare('SELECT * FROM erp_print_agents WHERE id=? AND active=1');$s->execute([$id]);$a=$s->fetch(PDO::FETCH_ASSOC);
        if (!$a || !hash_equals($a['token_hash'],hash('sha256',$token))) throw new RuntimeException('Credenciais inválidas.');return $a;
    }
    public function heartbeat($agent,array $inventory,$version) {
        if(count($inventory)>100 || strlen($version)>60)throw new InvalidArgumentException('Inventário inválido.');
        foreach($inventory as $p) if(!is_array($p)||!isset($p['name'])||strlen($p['name'])>240)throw new InvalidArgumentException('Fila inválida.');
        $this->db->prepare('UPDATE erp_print_agents SET last_seen=CURRENT_TIMESTAMP,version=?,inventory=? WHERE id=?')->execute([$version,json_encode($inventory),$agent]);
        $this->db->prepare('UPDATE erp_printers SET online=0 WHERE agent_id=?')->execute([$agent]);
        $s=$this->db->prepare('UPDATE erp_printers SET online=?,last_seen=CURRENT_TIMESTAMP WHERE agent_id=? AND windows_queue=?');
        foreach($inventory as $p)$s->execute([!empty($p['online'])?1:0,$agent,$p['name']]);
    }
    public function choices($center,$type,$format,$manual=false) {
        $s=$this->db->prepare('SELECT p.*,a.is_default,a.priority,a.manual_allowed,a.is_alternative,g.last_seen agent_last_seen FROM erp_work_center_printers a JOIN erp_printers p ON p.id=a.printer_id JOIN erp_work_centers w ON w.id=a.work_center_id JOIN erp_print_agents g ON g.id=p.agent_id AND g.active=1 WHERE a.work_center_id=? AND a.type_id=? AND a.format_id=? AND a.active=1 AND p.is_active=1 AND w.is_active=1'.($manual?' AND a.manual_allowed=1':'').' ORDER BY a.is_default DESC,a.priority,p.id');
        $s->execute([$center,$type,$format]);return $s->fetchAll(PDO::FETCH_ASSOC);
    }
    public function enqueue($user,$center,$template,$printer,$copies,$content,$mime,$key,$expectedDpi=null) {
        if (!$this->enabled())throw new RuntimeException('Impressão central desativada.');
        if(!preg_match('/^[a-f0-9]{32,64}$/D',$key)||$copies<1||$copies>100||strlen($content)<1||strlen($content)>10485760||!in_array($mime,['application/pdf','application/zpl'],true))throw new InvalidArgumentException('Trabalho inválido.');
        $s=$this->db->prepare('SELECT * FROM erp_print_templates WHERE id=? AND active=1');$s->execute([$template]);$t=$s->fetch(PDO::FETCH_ASSOC);if(!$t)throw new InvalidArgumentException('Modelo inválido.');
        $s=$this->db->prepare('SELECT id FROM erp_print_jobs WHERE user_id=? AND request_key=?');$s->execute([$user,$key]);$existing=$s->fetchColumn();if($existing)return $existing;
        $options=$this->choices($center,$t['type_id'],$t['format_id']);$options=array_values(array_filter($options,function($p)use($mime){return $p['language']===($mime==='application/pdf'?'driver':'zpl');}));$selected=null;
        foreach($options as $p)if(($printer && $p['id']==$printer && $p['manual_allowed'])||(!$printer&&($p['is_default']||count($options)===1))){$selected=$p;break;}
        if(!$selected)throw new InvalidArgumentException('Escolha uma impressora autorizada e compatível.');
        if($mime==='application/zpl'&&$expectedDpi!==null&&(int)$selected['dpi']!==(int)$expectedDpi)throw new RuntimeException('DPI alterado: regenere a etiqueta a partir do original.');
        if (($mime==='application/zpl' && $selected['language']!=='zpl')||($mime==='application/pdf'&&$selected['language']!=='driver'))throw new InvalidArgumentException('Linguagem incompatível.');
        if(!in_array($selected['connection_type'],['windows_usb','ipp','raw_tcp'],true))throw new RuntimeException('Este transporte ainda não está validado. Use uma fila Windows.');
        if($mime==='application/pdf'&&substr($content,0,5)!=='%PDF-')throw new InvalidArgumentException('PDF inválido.');
        $id=bin2hex(random_bytes(16));$s=$this->db->prepare('INSERT INTO erp_print_jobs(id,user_id,work_center_id,template_id,type_id,format_id,printer_id,agent_id,queue_name,language,dpi,copies,mime,content,content_hash,request_key) SELECT ?,?,?,?,?,?,?,?,?,?,?,?,?,CAST(? AS BLOB),?,? WHERE (SELECT COALESCE(SUM(length(content)),0) FROM erp_print_jobs)<524288000 AND (SELECT COUNT(*) FROM erp_print_jobs WHERE state IN ("pending","reserved","processing"))<5000');
        try {$s->execute([$id,$user,$center,$template,$t['type_id'],$t['format_id'],$selected['id'],$selected['agent_id'],$selected['windows_queue'],$selected['language'],$selected['dpi'],$copies,$mime,$content,hash('sha256',$content),$key]);}
        catch(PDOException $e){$q=$this->db->prepare('SELECT id FROM erp_print_jobs WHERE user_id=? AND request_key=?');$q->execute([$user,$key]);$v=$q->fetchColumn();if($v)return $v;throw $e;}
        if(!$s->rowCount())throw new RuntimeException('Limite da fila atingido. Contacte a administração.');
        $this->audit($user,'enqueued',$id);return $id;
    }
    public function claim($agent) {
        if(!$this->enabled())return null;
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            // Only reservations that never crossed the processing barrier are safe to retry.
            $this->db->exec("UPDATE erp_print_jobs SET state='pending',lease_token=NULL,lease_until=NULL WHERE state='reserved' AND lease_until<CURRENT_TIMESTAMP");
            $this->db->exec("UPDATE erp_print_jobs SET state='unknown',error='Comunicação interrompida após início do envio',finished_at=CURRENT_TIMESTAMP WHERE state='processing' AND lease_until<CURRENT_TIMESTAMP");
            $s=$this->db->prepare("SELECT j.id FROM erp_print_jobs j JOIN erp_printers p ON p.id=j.printer_id WHERE j.agent_id=? AND j.state='pending' AND p.is_active=1 AND p.online=1 AND p.last_seen>datetime('now','-2 minutes') ORDER BY j.created_at,j.id LIMIT 1");$s->execute([$agent]);$id=$s->fetchColumn();
            if(!$id){$this->db->exec('COMMIT');return null;}
            $token=bin2hex(random_bytes(24));$this->db->prepare("UPDATE erp_print_jobs SET state='reserved',lease_token=?,lease_until=datetime('now','+2 minutes'),attempts=attempts+1 WHERE id=? AND state='pending'")->execute([$token,$id]);
            $s=$this->db->prepare('SELECT j.id,j.lease_token,j.queue_name,j.mime,j.copies,j.content_hash,j.language,j.dpi,f.width_mm,f.height_mm,f.orientation FROM erp_print_jobs j JOIN erp_print_formats f ON f.id=j.format_id WHERE j.id=?');$s->execute([$id]);$job=$s->fetch(PDO::FETCH_ASSOC);$this->audit($agent,'reserved',$id);$this->db->exec('COMMIT');return $job;
        }catch(Throwable $e){$this->db->exec('ROLLBACK');throw $e;}
    }
    private function job($agent,$id,$token) {
        $s=$this->db->prepare('SELECT * FROM erp_print_jobs WHERE id=? AND agent_id=? AND lease_token=?');$s->execute([$id,$agent,$token]);$j=$s->fetch(PDO::FETCH_ASSOC);if(!$j)throw new RuntimeException('Trabalho não autorizado.');return $j;
    }
    public function content($agent,$id,$token) {
        $j=$this->job($agent,$id,$token);if($j['state']!=='reserved'||strtotime($j['lease_until'].' UTC')<=time())throw new RuntimeException('Reserva expirada.');return $j;
    }
    public function report($agent,$id,$token,$state,$error='',$spool='') {
        if(!in_array($state,['processing','spooler_accepted','completed','error','unknown'],true)||strlen($error)>2000||strlen($spool)>100)throw new InvalidArgumentException('Estado inválido.');
        $this->db->exec('BEGIN IMMEDIATE');
        try{
            $j=$this->job($agent,$id,$token);
            if($j['state']===$state){$this->db->exec('COMMIT');return;}
            $allowed=['reserved'=>['processing','error','unknown'],'processing'=>['spooler_accepted','unknown'],'spooler_accepted'=>['completed','unknown']];
            if(!isset($allowed[$j['state']])||!in_array($state,$allowed[$j['state']],true)||($j['state']==='reserved'&&strtotime($j['lease_until'].' UTC')<=time()))throw new RuntimeException('Transição inválida ou reserva expirada.');
            $this->db->prepare("UPDATE erp_print_jobs SET state=?,error=?,spool_id=?,started_at=COALESCE(started_at,CURRENT_TIMESTAMP),lease_until=datetime('now','+10 minutes'),finished_at=CASE WHEN ? IN ('completed','error','unknown') THEN CURRENT_TIMESTAMP ELSE NULL END WHERE id=?")->execute([$state,$error,$spool,$state,$id]);$this->audit($agent,$state,$id);$this->db->exec('COMMIT');
        }catch(Throwable $e){$this->db->exec('ROLLBACK');throw $e;}
    }
    public function cancel($id,$actor) {
        $s=$this->db->prepare("UPDATE erp_print_jobs SET state='cancelled',finished_at=CURRENT_TIMESTAMP WHERE id=? AND state='pending'");$s->execute([$id]);if(!$s->rowCount())throw new RuntimeException('Só é possível cancelar trabalhos pendentes.');$this->audit($actor,'cancelled',$id);
    }
    public function reroute($id,$printer,$actor) {
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $s=$this->db->prepare("SELECT * FROM erp_print_jobs WHERE id=? AND state='pending'");$s->execute([$id]);$j=$s->fetch(PDO::FETCH_ASSOC);if(!$j)throw new RuntimeException('Só é possível transferir trabalhos pendentes.');
            $selected=null;foreach($this->choices($j['work_center_id'],$j['type_id'],$j['format_id'],true) as $p)if($p['id']==$printer)$selected=$p;
            if(!$selected||$selected['language']!==$j['language']||!in_array($selected['connection_type'],['windows_usb','ipp','raw_tcp'],true)||($j['mime']==='application/zpl'&&(int)$selected['dpi']!==(int)$j['dpi']))throw new RuntimeException('Destino incompatível.');
            $this->db->prepare("UPDATE erp_print_jobs SET printer_id=?,agent_id=?,queue_name=?,lease_token=NULL,lease_until=NULL WHERE id=? AND state='pending'")->execute([$printer,$selected['agent_id'],$selected['windows_queue'],$id]);
            $this->audit($actor,'rerouted',$id,json_encode(['from'=>$j['printer_id'],'to'=>$printer]));$this->db->exec('COMMIT');
        }catch(Throwable $e){$this->db->exec('ROLLBACK');throw $e;}
    }
    public function reprint($id,$actor,$acknowledged,$key) {
        if(!$acknowledged)throw new RuntimeException('Confirme que pretende uma nova impressão, mesmo que o original já tenha saído.');
        $s=$this->db->prepare("SELECT * FROM erp_print_jobs WHERE id=? AND state IN ('spooler_accepted','completed','unknown','error','cancelled')");$s->execute([$id]);$j=$s->fetch(PDO::FETCH_ASSOC);
        if(!$j||$j['content']===null)throw new RuntimeException('Conteúdo indisponível ou trabalho ainda em curso.');
        $s=$this->db->prepare('SELECT type_id,format_id FROM erp_print_templates WHERE id=?');$s->execute([$j['template_id']]);$template=$s->fetch(PDO::FETCH_ASSOC);if(!$template||(int)$template['type_id']!==(int)$j['type_id']||(int)$template['format_id']!==(int)$j['format_id'])throw new RuntimeException('Modelo alterado: regenere o documento.');
        $new=$this->enqueue($actor,$j['work_center_id'],$j['template_id'],0,$j['copies'],$j['content'],$j['mime'],$key,$j['dpi']);$this->audit($actor,'reprinted',$new,'original:'.$id);return $new;
    }
    public function cleanup($days=30) {
        $days=max(7,(int)$days);
        // Keep metadata and audit; purge only terminal payloads. Never remove pending jobs.
        $this->db->exec("UPDATE erp_print_jobs SET content=NULL WHERE state IN ('completed','cancelled','error','unknown','spooler_accepted') AND created_at<datetime('now','-".$days." days')");
    }
}
