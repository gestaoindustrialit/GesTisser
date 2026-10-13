<?php
require_once __DIR__.'/Validator.php';
/** Isolated Bobinas history. PHP 7.0. No operational writers are called. */
final class LegacyOfHistory
{
    private $pdo;
    private $tables=[];
    private $catalog=[];
    public function __construct(PDO $pdo) {$this->pdo=$pdo;}
    private function all($sql,array $params=[]) {$s=$this->pdo->prepare($sql);$s->execute($params);return $s->fetchAll(PDO::FETCH_ASSOC);}
    private function row($sql,array $params=[]) {$r=$this->all($sql,$params);return $r?$r[0]:null;}
    public function ready() {return $this->exists('erp_legacy_production_orders');}
    private function exists($table) {
        if(!isset($this->tables[$table]))$this->tables[$table]=(bool)$this->row("SELECT name FROM sqlite_master WHERE type='table' AND name=?",[$table]);
        return $this->tables[$table];
    }
    public static function hash($data) {return hash('sha256',json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));}
    private static function environment($environment) {if($environment!=='gestisser-dev')throw new RuntimeException('Escrita permitida exclusivamente em gestisser-dev.');}
    private function admin($userId) {
        $u=$this->row('SELECT id,is_admin,is_active FROM users WHERE id=?',[$userId]);
        if(!$u||(int)$u['is_admin']!==1||(int)$u['is_active']!==1)throw new RuntimeException('Administrador ativo obrigatório.');
    }
    private function safeTargets() {
        // Triggers could write outside the historical layer, so fail closed.
        foreach($this->all("SELECT name,tbl_name FROM sqlite_master WHERE type='trigger'") as $t)
            if(strpos($t['tbl_name'],'erp_legacy_of_')===0||$t['tbl_name']==='erp_legacy_production_orders')throw new RuntimeException('Trigger histórico exige revisão: '.$t['name']);
    }
    /** Snapshot without BackupManager::ensureSchema(), which writes app_settings. */
    private function backup($directory) {
        if(version_compare($this->pdo->query('SELECT sqlite_version()')->fetchColumn(),'3.27.0','<'))throw new RuntimeException('Backup seguro requer SQLite >=3.27 ou adaptador de snapshot revisto.');
        $db=$this->row('PRAGMA database_list');
        if(!$db||!is_file($db['file']))throw new RuntimeException('Backup exige base SQLite em ficheiro.');
        if(!is_dir($directory)&&!mkdir($directory,0700,true))throw new RuntimeException('Diretório de backup indisponível.');
        chmod($directory,0700);
        file_put_contents($directory.'/.htaccess',"Require all denied\nDeny from all\n");
        $path=$directory.'/legacy-of-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(8)).'.sqlite';
        $this->pdo->exec('VACUUM main INTO '.$this->pdo->quote($path));chmod($path,0600);
        $check=new PDO('sqlite:'.$path);$check->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        if($check->query('PRAGMA integrity_check')->fetchColumn()!=='ok'||$check->query('PRAGMA foreign_key_check')->fetch())throw new RuntimeException('Backup não passou integridade/FK.');
        $check=null;
        return ['path'=>$path,'sha256'=>hash_file('sha256',$path)];
    }
    public function setup($environment,$userId,$backupDirectory) {
        self::environment($environment);$this->admin($userId);$this->safeTargets();
        $backup=$this->backup($backupDirectory);
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec(file_get_contents(__DIR__.'/legacy-of-schema.sql'));
            $s=$this->pdo->prepare('INSERT INTO erp_legacy_of_audit(user_id,action,after_json) VALUES (?,"schema_setup",?)');$s->execute([$userId,json_encode($backup)]);
            $this->pdo->commit();$this->tables=[];
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
        return $backup;
    }
    private function reference($table,$code,$id,array $approval,&$errors,&$warnings) {
        if(!isset($this->catalog[$table])) {
            $this->catalog[$table]=['ids'=>[],'codes'=>[]];
            foreach($this->all('SELECT id,code FROM '.$table) as $r){$this->catalog[$table]['ids'][(string)$r['id']]=$r; $this->catalog[$table]['codes'][(string)$r['code']][]=(int)$r['id'];}
        }
        $c=$this->catalog[$table];$found=$code!==''?($c['codes'][$code]??[]):[];
        if(count($found)>1){$errors[]='Código ambíguo: '.$table.' / '.$code;return null;}
        if($id!=='') {
            if(!ctype_digit($id)||!isset($c['ids'][$id])){$errors[]='ID inexistente/inválido: '.$table.' / '.$id;return null;}
            if($found&&(int)$id!==$found[0]){$errors[]='ID contradiz código original: '.$table;return null;}
            if($found)return $found[0];
            if(($approval['id']??'')===$id&&trim($approval['evidence']??'')!=='')return (int)$id;
            $warnings[]='ID existe mas correspondência não comprovada: '.$table.'; requer evidência administrativa.';return null;
        }
        if($found)return $found[0];
        $warnings[]='Referência pendente: '.$table.' / '.$code;return null;
    }
    private function normalize(array $d,$entity,&$errors) {
        $result=$d;
        $numbers=$entity==='ofs'?['quantidade_planeada','quantidade_produzida','tempo_preparacao_horas','tempo_execucao_horas','tempo_total_horas','custo_mao_obra','custo_materiais','outros_custos','custo_total','custo_unitario']:['quantidade','horas_previstas','horas_reais','custo_registado'];
        foreach($numbers as $k) {
            $result[$k]=null;if(($d[$k]??'')==='')continue;
            try {$n=HistoricalValidator::number($d[$k]);if($n<0)throw new RuntimeException('Valor negativo');$result[$k]=$n;}catch(Throwable $e){$errors[]=$k.': '.$e->getMessage();}
        }
        $dates=$entity==='ofs'?['data_criacao','data_inicio','data_fim']:['data_inicio','data_fim'];
        foreach($dates as $k){$result[$k]=null;if(($d[$k]??'')==='')continue;try{$result[$k]=HistoricalValidator::date($d[$k]);}catch(Throwable $e){$errors[]=$k.': '.$e->getMessage();}}
        if($result['data_inicio']&&$result['data_fim']&&$result['data_inicio']>$result['data_fim'])$errors[]='Data de fim anterior ao início.';
        if($entity==='of_operacoes') {
            $v=$d['sequencia']??'';$result['sequencia']=null;
            if($v!==''){if(!ctype_digit($v)||strlen($v)>9)$errors[]='Sequência inválida.';else $result['sequencia']=(int)$v;}
        }
        $money=$entity==='ofs'?['custo_mao_obra','custo_materiais','outros_custos','custo_total','custo_unitario']:['custo_registado'];
        $hasMoney=false;foreach($money as $k)if($result[$k]!==null)$hasMoney=true;
        if(($d['tipo_custo']??'')!==''&&!in_array($d['tipo_custo'],['real','estimated'],true))$errors[]='tipo_custo inválido.';
        if($hasMoney){
            if(!preg_match('/^[A-Z]{3}$/D',$d['moeda']??''))$errors[]='Custos exigem moeda ISO de três letras.';
            if(!in_array($d['tipo_custo']??'',['real','estimated'],true))$errors[]='Custos exigem tipo_custo: real ou estimated.';
            if($entity==='ofs'&&(trim($d['origem_custo']??'')===''||trim($d['metodo_calculo']??'')===''))$errors[]='Custos exigem origem_custo e metodo_calculo.';
            if($entity==='of_operacoes'&&trim($d['origem_registo']??'')==='')$errors[]='Custo da operação exige origem_registo.';
        }
        return $result;
    }
    /** Read-only, including before historical schema setup. Inputs are parsed XLSX rows. */
    public function validate(array $batch) {
        $this->catalog=[];
        $report=['schema_hash'=>HistoricalValidator::schemaHash($this->pdo),'batch_hash'=>self::hash($batch),'entities'=>[],'errors'=>0,'inserted'=>0,'skipped'=>0,'pending'=>0];
        foreach($batch as $entity=>$file)if(!in_array($entity,['ofs','of_operacoes'],true))throw new RuntimeException('Este fluxo aceita apenas 03_ofs.xlsx e 04_of_operacoes.xlsx.');
        $existingOf=[];$existingOp=[];$ofById=[];$ofByNumber=[];$operationalMappings=[];
        if($this->exists('erp_legacy_import_map'))foreach($this->all('SELECT entity_type,legacy_id FROM erp_legacy_import_map WHERE source_system="Bobinas" AND target_table IN ("erp_production_orders","erp_production_order_operations")') as $map)$operationalMappings[$map['entity_type']][$map['legacy_id']]=true;
        if($this->ready()) {
            foreach($this->all('SELECT id,legacy_id,legacy_of_number,source_hash FROM erp_legacy_production_orders WHERE source_system="Bobinas"') as $r){$existingOf[$r['legacy_id']]=$r;$ofById[$r['legacy_id']]=['id'=>(int)$r['id'],'number'=>$r['legacy_of_number']];$ofByNumber[$r['legacy_of_number']][$r['legacy_id']]=$ofById[$r['legacy_id']];}
            foreach($this->all('SELECT legacy_id,source_hash,legacy_of_id FROM erp_legacy_of_cost_details WHERE source_system="Bobinas"') as $r)$existingOp[$r['legacy_id']]=$r;
        }
        foreach(['ofs','of_operacoes'] as $entity) {
            if(!isset($batch[$entity]))continue;
            $file=$batch[$entity];$rows=$file['rows']??[];$seen=[];
            foreach($rows as $r)if(($r['data']['importar']??'')==='1')$seen[$r['data']['legacy_id']??'']=($seen[$r['data']['legacy_id']??'']??0)+1;
            foreach($rows as $r) {
                $d=$r['data'];$errors=[];$warnings=[];$refs=[];$legacy=$d['legacy_id']??'';$flag=$d['importar']??'';
                if($flag===''||$flag==='0'){$report['skipped']++;$report['entities'][$entity][]=['line'=>$r['line'],'legacy_id'=>$legacy,'status'=>'ignored','errors'=>[],'warnings'=>[]];continue;}
                if($flag!=='1')$errors[]='importar deve ser 0, 1 ou vazio.';
                if($legacy==='')$errors[]='legacy_id obrigatório.';
                if(isset($operationalMappings[$entity][$legacy]))$errors[]='Identificador já mapeado pelo importador antigo em tabela operacional; exige reconciliação manual, sem migração automática.';
                if(($seen[$legacy]??0)>1)$errors[]='legacy_id repetido no lote.';
                if(($d['erro_validacao']??'')!=='')$errors[]=$d['erro_validacao'];
                if($entity==='ofs'&&trim($d['numero_of']??'')==='')$errors[]='numero_of obrigatório.';
                if($entity==='of_operacoes'&&trim($d['operacao']??'')==='')$errors[]='operacao original obrigatória.';
                // Source hash excludes administrative associations and import selection, but includes every original business field.
                $source=$d;foreach(['gestisser_customer_id','gestisser_product_id','importar','erro_validacao'] as $k)unset($source[$k]);ksort($source);$hash=self::hash($source);
                $n=$this->normalize($d,$entity,$errors);
                if($entity==='ofs') {
                    $approval=$r['approvals']??[];
                    $refs['customer']=$this->reference('erp_customers',$d['cliente_codigo']??'',($d['gestisser_customer_id']??'')!==''?$d['gestisser_customer_id']:($approval['customer']['id']??''),$approval['customer']??[],$errors,$warnings);
                    $refs['product']=$this->reference('erp_finished_products',$d['artigo_codigo']??'',($d['gestisser_product_id']??'')!==''?$d['gestisser_product_id']:($approval['product']['id']??''),$approval['product']??[],$errors,$warnings);
                    if(!$refs['customer']||!$refs['product'])$report['pending']++;
                    $prior=$existingOf[$legacy]??null;
                }else {
                    $parent=$d['of_legacy_id']??'';$number=$d['numero_of']??'';
                    $candidates=$ofByNumber[$number]??[];
                    if($parent!=='')$resolved=$ofById[$parent]??null;
                    else $resolved=count($candidates)===1?reset($candidates):null;
                    if(!$resolved)$errors[]='OF pai inexistente, inválida ou número ambíguo; indicar of_legacy_id.';
                    elseif($number!==''&&$resolved['number']!==$number)$errors[]='of_legacy_id contradiz numero_of.';
                    else $refs['parent']=$resolved;
                    $prior=$existingOp[$legacy]??null;
                    if($prior&&isset($resolved['id'])&&(int)$prior['legacy_of_id']!==$resolved['id'])$errors[]='Operação já ligada a outra OF.';
                }
                if($prior&&$prior['source_hash']!==$hash)$errors[]='Identificador histórico existente com dados diferentes: revisão obrigatória.';
                $status=$errors?'error':($prior?'duplicate':'valid');
                if($errors)$report['errors']++;elseif($prior)$report['skipped']++;else $report['inserted']++;
                $out=['line'=>$r['line'],'legacy_id'=>$legacy,'status'=>$status,'errors'=>$errors,'warnings'=>$warnings,'data'=>$n,'refs'=>$refs,'source_hash'=>$hash,'original'=>$d,'approvals'=>$r['approvals']??[]];
                $report['entities'][$entity][]=$out;
                if($entity==='ofs'){
                    if($errors){unset($ofById[$legacy]);foreach($ofByNumber as &$group)unset($group[$legacy]);unset($group);}
                    else {$entry=$prior?['id'=>(int)$prior['id'],'number'=>$d['numero_of']]:['legacy_id'=>$legacy,'number'=>$d['numero_of']];$ofById[$legacy]=$entry;$ofByNumber[$d['numero_of']][$legacy]=$entry;}
                }
            }
        }
        return $report;
    }
    private function insert($table,array $data) {
        $s=$this->pdo->prepare('INSERT INTO '.$table.' ('.implode(',',array_keys($data)).') VALUES ('.implode(',',array_fill(0,count($data),'?')).')');$s->execute(array_values($data));return (int)$this->pdo->lastInsertId();
    }
    public function import(array $batch,array $previous,$environment,$userId,$confirmation,$backupDirectory) {
        self::environment($environment);$this->admin($userId);$this->safeTargets();
        if($confirmation!=='IMPORTAR LOTE HISTORICO')throw new RuntimeException('Confirmação explícita obrigatória.');
        if(!$this->ready())throw new RuntimeException('Preparar estrutura histórica primeiro.');
        if(($previous['batch_hash']??'')!==self::hash($batch))throw new RuntimeException('Lote alterado; repetir dry-run.');
        $report=$this->validate($batch);if($report['errors'])throw new RuntimeException('Lote com erros; importação bloqueada.');
        if(self::hash($report)!==self::hash($previous))throw new RuntimeException('Referências/dados mudaram após dry-run; validar novamente.');
        $backup=$this->backup($backupDirectory);$started=false;
        try {
            $this->pdo->exec('PRAGMA foreign_keys=ON');$this->pdo->exec('BEGIN IMMEDIATE');$started=true;
            $this->catalog=[];$this->admin($userId);$this->safeTargets();$locked=$this->validate($batch);
            if(self::hash($locked)!==self::hash($report))throw new RuntimeException('Lote/referências mudaram antes da escrita; repetir dry-run.');
            $batchId=$this->insert('erp_legacy_of_batches',['user_id'=>$userId,'batch_hash'=>$report['batch_hash'],'status'=>'success','backup_path'=>$backup['path'],'backup_sha256'=>$backup['sha256'],'report_json'=>json_encode($report,JSON_UNESCAPED_UNICODE)]);
            $resolved=[];
            foreach($report['entities']['ofs']??[] as $row) {
                if($row['status']!=='valid')continue;$d=$row['data'];$r=$row['refs'];
                $id=$this->insert('erp_legacy_production_orders',['source_system'=>'Bobinas','legacy_id'=>$row['legacy_id'],'legacy_of_number'=>$d['numero_of'],'legacy_order_id'=>$d['encomenda_legacy_id']?:null,'legacy_order_number'=>$d['numero_encomenda']?:null,'legacy_customer_code'=>$d['cliente_codigo'],'legacy_product_code'=>$d['artigo_codigo'],'gestisser_customer_id'=>$r['customer'],'gestisser_product_id'=>$r['product'],'customer_resolution'=>$r['customer']?'resolved':'pending','product_resolution'=>$r['product']?'resolved':'pending','planned_quantity'=>$d['quantidade_planeada'],'produced_quantity'=>$d['quantidade_produzida'],'quantity_unit'=>($d['unidade']??'')?:null,'created_at_legacy'=>$d['data_criacao'],'started_at_legacy'=>$d['data_inicio'],'finished_at_legacy'=>$d['data_fim'],'legacy_status'=>$d['estado'],'observations'=>$d['observacoes'],'source_hash'=>$row['source_hash'],'original_json'=>json_encode($row['original'],JSON_UNESCAPED_UNICODE),'import_batch_id'=>$batchId]);
                $resolved[$row['legacy_id']]=$id;
                $this->insert('erp_legacy_of_cost_summary',['legacy_of_id'=>$id,'total_production_time'=>$d['tempo_total_horas'],'setup_time'=>$d['tempo_preparacao_horas'],'execution_time'=>$d['tempo_execucao_horas'],'time_unit'=>'h','produced_quantity'=>$d['quantidade_produzida'],'labor_cost'=>$d['custo_mao_obra'],'material_cost'=>$d['custo_materiais'],'other_cost'=>$d['outros_custos'],'total_cost'=>$d['custo_total'],'unit_cost'=>$d['custo_unitario'],'currency'=>($d['moeda']??'')?:null,'cost_type'=>($d['tipo_custo']??'')?:null,'origin'=>($d['origem_custo']??'')?:'03_ofs.xlsx','calculation_method'=>($d['metodo_calculo']??'')?:'Valores fornecidos; sem cálculo nem estimativa']);
                $this->insert('erp_legacy_of_audit',['user_id'=>$userId,'action'=>'import_of','legacy_of_id'=>$id,'import_batch_id'=>$batchId,'after_json'=>json_encode($row['refs']),'evidence'=>json_encode($row['approvals'],JSON_UNESCAPED_UNICODE)]);
            }
            foreach($report['entities']['of_operacoes']??[] as $row) {
                if($row['status']!=='valid')continue;$d=$row['data'];$p=$row['refs']['parent'];$of=$p['id']??($resolved[$p['legacy_id']]??null);
                if(!$of)throw new RuntimeException('OF pai não resolvida na transação.');
                $this->insert('erp_legacy_of_cost_details',['legacy_of_id'=>$of,'source_system'=>'Bobinas','legacy_id'=>$row['legacy_id'],'operation_code'=>($d['operacao_codigo']??'')?:$d['operacao'],'operation_name'=>($d['operacao_nome']??'')?:$d['operacao'],'sequence_no'=>$d['sequencia'],'registered_time'=>$d['horas_reais'],'planned_time'=>$d['horas_previstas'],'processed_quantity'=>$d['quantidade'],'registered_cost'=>$d['custo_registado'],'time_unit'=>'h','quantity_unit'=>($d['unidade']??'')?:null,'currency'=>($d['moeda']??'')?:null,'cost_type'=>($d['tipo_custo']??'')?:null,'origin'=>($d['origem_registo']??'')?:'04_of_operacoes.xlsx','started_at_legacy'=>$d['data_inicio'],'finished_at_legacy'=>$d['data_fim'],'legacy_status'=>$d['estado'],'source_hash'=>$row['source_hash'],'original_json'=>json_encode($row['original'],JSON_UNESCAPED_UNICODE),'import_batch_id'=>$batchId]);
            }
            if($this->pdo->query('PRAGMA foreign_key_check')->fetch())throw new RuntimeException('Validação FK falhou.');
            $this->pdo->exec('COMMIT');$started=false;return ['batch_id'=>$batchId,'backup'=>$backup,'report'=>$report];
        }catch(Throwable $e){
            if($started)$this->pdo->exec('ROLLBACK');
            $this->insert('erp_legacy_of_batches',['user_id'=>$userId,'batch_hash'=>self::hash($batch),'status'=>'failed','backup_path'=>$backup['path'],'backup_sha256'=>$backup['sha256'],'report_json'=>json_encode(['error'=>$e->getMessage(),'report'=>$report],JSON_UNESCAPED_UNICODE)]);throw $e;
        }
    }
    /** Explicit mapping correction only; original codes/JSON/hash are immutable. */
    public function resolve($id,$kind,$target,$evidence,$environment,$userId,$backupDirectory) {
        self::environment($environment);$this->admin($userId);$this->safeTargets();
        if(!in_array($kind,['customer','product'],true)||trim($evidence)==='')throw new RuntimeException('Tipo e evidência obrigatórios.');
        $table=$kind==='customer'?'erp_customers':'erp_finished_products';$field='gestisser_'.$kind.'_id';$codeField=$kind==='customer'?'legacy_customer_code':'legacy_product_code';
        $this->backup($backupDirectory);$this->pdo->beginTransaction();
        try {
            $before=$this->row('SELECT * FROM erp_legacy_production_orders WHERE id=?',[$id]);$errors=[];$warnings=[];
            if(!$before)throw new RuntimeException('OF inexistente.');
            $target=(string)$target;$verified=$this->reference($table,$before[$codeField],$target,['id'=>$target,'evidence'=>$evidence],$errors,$warnings);
            if($errors||!$verified)throw new RuntimeException(implode(' ',$errors)?:'Referência não comprovada.');
            $s=$this->pdo->prepare('UPDATE erp_legacy_production_orders SET '.$field.'=?,'.$kind.'_resolution="resolved" WHERE id=?');$s->execute([$verified,$id]);
            $this->insert('erp_legacy_of_audit',['user_id'=>$userId,'action'=>'resolve_'.$kind,'legacy_of_id'=>$id,'before_json'=>json_encode([$field=>$before[$field]]),'after_json'=>json_encode([$field=>$verified]),'evidence'=>$evidence]);$this->pdo->commit();
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
    public function detail($id) {
        if(!$this->ready())return null;
        $r=$this->row('SELECT o.*,c.name customer_name,a.description product_name FROM erp_legacy_production_orders o LEFT JOIN erp_customers c ON c.id=o.gestisser_customer_id LEFT JOIN erp_finished_products a ON a.id=o.gestisser_product_id WHERE o.id=?',[$id]);
        if(!$r)return null;$r['summary']=$this->row('SELECT * FROM erp_legacy_of_cost_summary WHERE legacy_of_id=?',[$id]);$r['operations']=$this->all('SELECT * FROM erp_legacy_of_cost_details WHERE legacy_of_id=? ORDER BY sequence_no,id LIMIT 1001',[$id]);$r['operations_truncated']=count($r['operations'])>1000;$r['operations']=array_slice($r['operations'],0,1000);return $r;
    }
    public static function filters(array $input) {
        $f=['year'=>'','q'=>'','origin'=>'','customer'=>0,'article'=>0,'p'=>1,'pending'=>false];
        foreach(['q','year','origin'] as $k)if(is_scalar($input[$k]??null))$f[$k]=mb_substr(trim((string)$input[$k]),0,120);
        if(!preg_match('/^[0-9]{4}$/D',$f['year']))$f['year']='';
        if(!in_array($f['origin'],['','Bobinas','GesTISSER'],true))$f['origin']='';
        foreach(['customer','article','p'] as $k)if(is_scalar($input[$k]??null)&&ctype_digit((string)$input[$k]))$f[$k]=min(100000000,max($k==='p'?1:0,(int)$input[$k]));
        $f['pending']=($input['pending']??'')==='1';return $f;
    }
    /** Unified consultation uses a discriminated source and id; never consumed by active production. */
    public function listing(array $f,$unified=true) {
        $branches=[];$params=[];
        foreach(['GesTISSER','Bobinas'] as $source){
            if($source==='GesTISSER'&&(!$unified||!$this->exists('erp_production_orders')))continue;
            if($source==='Bobinas'&&!$this->ready())continue;
            if(($f['origin']??'')!==''&&$f['origin']!==$source)continue;
            $legacy=$source==='Bobinas';$where=[];
            $fields=$legacy?['customer'=>'gestisser_customer_id','article'=>'gestisser_product_id','date'=>'created_at_legacy','number'=>'legacy_of_number']:['customer'=>'customer_id','article'=>'finished_product_id','date'=>'created_at','number'=>'order_number'];
            foreach(['customer','article'] as $k)if(!empty($f[$k])){$where[]='o.'.$fields[$k].'=?';$params[]=(int)$f[$k];}
            if(($f['year']??'')!==''){$where[]='o.'.$fields['date'].'>=? AND o.'.$fields['date'].'<?';$params[]=$f['year'].'-01-01';$params[]=((int)$f['year']+1).'-01-01';}
            if(($f['q']??'')!==''){$where[]='o.'.$fields['number'].' LIKE ? ESCAPE "\\"';$params[]='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$f['q']).'%';}
            if(!empty($f['pending'])) {if(!$legacy)continue;$where[]='(o.customer_resolution="pending" OR o.product_resolution="pending")';}
            $table=$legacy?'erp_legacy_production_orders':'erp_production_orders';
            $select=$legacy?'o.legacy_of_number number,o.created_at_legacy date,o.legacy_status status,o.produced_quantity,o.quantity_unit unit,o.legacy_product_code article_code,o.legacy_customer_code customer_code,o.gestisser_customer_id customer_id,o.gestisser_product_id article_id':'o.order_number number,o.created_at date,o.status,o.produced_quantity,u.code unit,a.code article_code,c.code customer_code,o.customer_id,o.finished_product_id article_id';
            $join=$legacy?'LEFT JOIN erp_customers c ON c.id=o.gestisser_customer_id LEFT JOIN erp_finished_products a ON a.id=o.gestisser_product_id':'LEFT JOIN erp_customers c ON c.id=o.customer_id LEFT JOIN erp_finished_products a ON a.id=o.finished_product_id LEFT JOIN erp_units u ON u.id=a.unit_id';
            $branches[]='SELECT o.id,"'.$source.'" origin,'.$select.',c.name customer_name,a.description article_name FROM '.$table.' o '.$join.($where?' WHERE '.implode(' AND ',$where):'');
        }
        if(!$branches)return ['rows'=>[],'total'=>0,'page'=>1,'pages'=>1];
        $sql=implode(' UNION ALL ',$branches);$total=(int)$this->row('SELECT COUNT(*) total FROM ('.$sql.')',$params)['total'];$pages=max(1,(int)ceil($total/20));$page=min($pages,max(1,(int)($f['p']??1)));
        $rows=$this->all('SELECT * FROM ('.$sql.') ORDER BY date DESC,origin,id DESC LIMIT 20 OFFSET '.(($page-1)*20),$params);return compact('rows','total','page','pages');
    }
    /** BI foundation: unknown units stay separated per OF; costs stay separated by currency/type. */
    public function indicators($articleId) {
        if(!$this->ready())return [];
        return $this->all('SELECT substr(o.created_at_legacy,1,4) year,o.quantity_unit,s.currency,s.cost_type,COUNT(*) orders,COUNT(DISTINCT o.gestisser_customer_id) customers,SUM(o.produced_quantity) quantity,AVG(s.execution_time) execution_hours,SUM(s.total_cost) recorded_total_cost FROM erp_legacy_production_orders o LEFT JOIN erp_legacy_of_cost_summary s ON s.legacy_of_id=o.id WHERE o.gestisser_product_id=? GROUP BY substr(o.created_at_legacy,1,4),COALESCE(o.quantity_unit,"unknown:"||o.id),s.currency,s.cost_type ORDER BY year DESC',[$articleId]);
    }
}
