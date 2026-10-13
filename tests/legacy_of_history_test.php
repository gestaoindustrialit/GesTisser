<?php
require_once __DIR__.'/../historical-import/LegacyOfHistory.php';
function check($c,$m){if(!$c)throw new RuntimeException($m);echo "PASS $m\n";}
function fails($fn,$m){try{$fn();}catch(Throwable $e){echo "PASS $m: ".$e->getMessage()."\n";return;}throw new RuntimeException($m);}
function input($entity,$values){$d=array_replace(array_fill_keys(HistoricalSpreadsheet::contracts()[$entity]['columns'],''),$values,['importar'=>'1']);return ['entity'=>$entity,'filename'=>$entity.'.xlsx','rows'=>[['line'=>2,'data'=>$d]]];}
$root=__DIR__.'/../work/legacy-of-test-'.bin2hex(random_bytes(6));mkdir($root,0700,true);$path=$root.'/database.sqlite';
$pdo=new PDO('sqlite:'.$path);$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$pdo->exec('PRAGMA foreign_keys=ON');
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,is_admin INTEGER,is_active INTEGER);INSERT INTO users VALUES(1,1,1),(2,0,1),(3,1,0);
CREATE TABLE erp_customers(id INTEGER PRIMARY KEY,code TEXT,name TEXT);INSERT INTO erp_customers VALUES(10,"C1","Cliente"),(11,"C2","Mesmo nome");
CREATE TABLE erp_units(id INTEGER PRIMARY KEY,code TEXT);INSERT INTO erp_units VALUES(1,"un");
CREATE TABLE erp_finished_products(id INTEGER PRIMARY KEY,code TEXT,description TEXT,unit_id INTEGER);INSERT INTO erp_finished_products VALUES(20,"A1","Artigo",1),(21,"A2","Outro",1);
CREATE TABLE erp_products(id INTEGER PRIMARY KEY,code TEXT);INSERT INTO erp_products VALUES(30,"OPERACIONAL");
CREATE TABLE erp_production_orders(id INTEGER PRIMARY KEY,order_number TEXT,customer_id INTEGER,finished_product_id INTEGER,product_id INTEGER,created_at TEXT,status TEXT,produced_quantity REAL);INSERT INTO erp_production_orders VALUES(1,"MESMO-NUMERO",10,20,30,"2026-01-01","Aberta",1);
CREATE TABLE erp_production_order_operations(id INTEGER PRIMARY KEY,production_order_id INTEGER);INSERT INTO erp_production_order_operations VALUES(1,1);
CREATE TABLE erp_stock_balances(id INTEGER,quantity REAL);INSERT INTO erp_stock_balances VALUES(1,500);
CREATE TABLE erp_stock_movements(id INTEGER,quantity REAL);INSERT INTO erp_stock_movements VALUES(1,500);
CREATE TABLE shopfloor_tasks(id INTEGER,status TEXT);INSERT INTO shopfloor_tasks VALUES(1,"Aberta");
CREATE TABLE erp_production_order_material_reservations(id INTEGER,quantity REAL);INSERT INTO erp_production_order_material_reservations VALUES(1,50);
CREATE TABLE planning(id INTEGER,priority INTEGER);INSERT INTO planning VALUES(1,99);
CREATE TABLE routing(id INTEGER,version INTEGER);INSERT INTO routing VALUES(1,5);');
$operational=['erp_customers','erp_finished_products','erp_products','erp_units','erp_production_orders','erp_production_order_operations','erp_stock_balances','erp_stock_movements','shopfloor_tasks','erp_production_order_material_reservations','planning','routing'];
function snapshot($pdo,$tables){$result=[];foreach($tables as $t)$result[$t]=$pdo->query('SELECT * FROM '.$t.' ORDER BY rowid')->fetchAll(PDO::FETCH_ASSOC);return LegacyOfHistory::hash($result);}
$baseline=snapshot($pdo,$operational);$service=new LegacyOfHistory($pdo);
$batch=['ofs'=>input('ofs',['legacy_id'=>'old-001','numero_of'=>'MESMO-NUMERO','cliente_codigo'=>'C1','artigo_codigo'=>'A1','quantidade_planeada'=>'12,5','quantidade_produzida'=>'10','data_criacao'=>'2020-01-02','estado'=>'Estado original livre']), 'of_operacoes'=>input('of_operacoes',['legacy_id'=>'operation-001','of_legacy_id'=>'old-001','numero_of'=>'MESMO-NUMERO','operacao'=>'Operação desconhecida','sequencia'=>'1','horas_reais'=>'2,5','quantidade'=>'10'])];
$before=hash_file('sha256',$path);$changes=$pdo->query('SELECT total_changes()')->fetchColumn();$pdo->exec('PRAGMA query_only=ON');
$r=$service->validate($batch);check($r['errors']===0&&$r['inserted']===2,'Valid OF and unknown operation catalog accepted');
check(hash_file('sha256',$path)===$before&&$changes===$pdo->query('SELECT total_changes()')->fetchColumn()&&!$service->ready(),'Dry-run performs zero SQLite writes or DDL');
$bad=$batch;$bad['ofs']['rows'][0]['data']['cliente_codigo']='UNKNOWN';$r=$service->validate($bad);check($r['errors']===0&&$r['pending']===1&&$r['entities']['ofs'][0]['refs']['customer']===null,'Unknown customer remains pending');
$bad=$batch;$bad['ofs']['rows'][0]['data']['artigo_codigo']='UNKNOWN';$r=$service->validate($bad);check($r['errors']===0&&$r['pending']===1,'Unknown article remains pending');
$bad=$batch;$bad['ofs']['rows'][0]['data']['gestisser_customer_id']='11';check($service->validate($bad)['errors']>0,'Contradictory customer ID blocked');
$bad=$batch;$bad['ofs']['rows'][0]['data']['gestisser_product_id']='999';check($service->validate($bad)['errors']>0,'Nonexistent product ID blocked');
$bad=$batch;$bad['ofs']['rows'][0]['data']['artigo_codigo']='UNKNOWN';$bad['ofs']['rows'][0]['data']['gestisser_product_id']='20';check($service->validate($bad)['entities']['ofs'][0]['refs']['product']===null,'Excel ID without proof never associates unknown code');
$bad['ofs']['rows'][0]['approvals']['product']=['id'=>'20','evidence'=>'Documento de correspondência assinado'];check($service->validate($bad)['entities']['ofs'][0]['refs']['product']===20,'Administrative evidence can resolve an unknown code');
$bad=$batch;$bad['ofs']['rows'][0]['data']['data_criacao']='2020-02-31';check($service->validate($bad)['errors']>0,'Impossible date rejected');
$bad=$batch;$bad['of_operacoes']['rows'][0]['data']['numero_of']='WRONG';check($service->validate($bad)['errors']>0,'Parent ID/number contradiction blocked');
$bad=$batch;$bad['ofs']['rows'][]=$bad['ofs']['rows'][0];check($service->validate($bad)['errors']===3,'Duplicate source IDs block parents and dependent operations');
$bad=$batch;$second=$bad['ofs']['rows'][0];$second['line']=3;$second['data']['legacy_id']='old-002';$bad['ofs']['rows'][]=$second;check($service->validate($bad)['errors']===0,'Visible OF numbers may repeat with distinct historical identifiers');
$bad['of_operacoes']['rows'][0]['data']['of_legacy_id']='';check($service->validate($bad)['errors']>0,'Ambiguous visible parent number requires source ID');
$bad=$batch;$bad['of_operacoes']['rows'][0]['data']['custo_registado']='10';check($service->validate($bad)['errors']>0,'Time/quantity never treated as financial cost; unproven costs blocked');
$pdo->exec('PRAGMA query_only=OFF');
fails(function()use($service,$root){$service->setup('production',1,$root.'/backups');},'Production setup rejected');
fails(function()use($service,$root){$service->setup('gestisser-dev',2,$root.'/backups');},'Non-admin setup rejected');
fails(function()use($service,$root){$service->setup('gestisser-dev',3,$root.'/backups');},'Inactive admin rejected');
$backup=$service->setup('gestisser-dev',1,$root.'/backups');check(hash_file('sha256',$backup['path'])===$backup['sha256'],'Schema setup backed up and verified');
$r=$service->validate($batch);
fails(function()use($service,$batch,$r,$root){$service->import($batch,$r,'gestisser-dev',1,'',$root.'/backups');},'Import requires exact explicit confirmation');
$run=$service->import($batch,$r,'gestisser-dev',1,'IMPORTAR LOTE HISTORICO',$root.'/backups');
check($run['report']['inserted']===2&&$pdo->query('SELECT COUNT(*) FROM erp_legacy_production_orders')->fetchColumn()==1,'One historical OF imported exclusively into historical table');
check(snapshot($pdo,$operational)===$baseline,'Operational OFs, stock, shopfloor, reservations, planning and routing unchanged');
$detail=$service->detail(1);check($detail['operations'][0]['registered_time']==2.5&&$detail['summary']['total_cost']===null&&$detail['quantity_unit']===null,'Hours preserved, missing units/costs stay NULL');
$r=$service->validate($batch);check($r['inserted']===0&&$r['skipped']===2,'Reimport identifies identical records');
$run=$service->import($batch,$r,'gestisser-dev',1,'IMPORTAR LOTE HISTORICO',$root.'/backups');check($pdo->query('SELECT COUNT(*) FROM erp_legacy_of_cost_details')->fetchColumn()==1,'Reimport creates no duplicate historical operations');
$bad=$batch;$bad['ofs']['rows'][0]['data']['quantidade_planeada']='13';check($service->validate($bad)['errors']>0,'Changed historical payload cannot overwrite prior record');
$pending=['ofs'=>input('ofs',['legacy_id'=>'pending','numero_of'=>'PENDING','cliente_codigo'=>'UNKNOWN-C','artigo_codigo'=>'UNKNOWN-A','quantidade_produzida'=>'3','data_criacao'=>'2019-01-01'])];
$service->import($pending,$service->validate($pending),'gestisser-dev',1,'IMPORTAR LOTE HISTORICO',$root.'/backups');$original=$service->detail(2);
$service->resolve(2,'customer','10','Comprovativo C-123','gestisser-dev',1,$root.'/backups');$service->resolve(2,'product','20','Comprovativo A-123','gestisser-dev',1,$root.'/backups');$resolved=$service->detail(2);
check($resolved['gestisser_customer_id']==10&&$resolved['gestisser_product_id']==20&&$resolved['original_json']===$original['original_json']&&$resolved['source_hash']===$original['source_hash'],'Pending resolution is audited and preserves immutable original values');
fails(function()use($service,$root){$service->resolve(1,'customer','11','Contraditório','gestisser-dev',1,$root.'/backups');},'Contradictory post-import resolution rejected');
check($service->listing(LegacyOfHistory::filters(['customer'=>'10','origin'=>'Bobinas']))['total']===2,'Consultation by customer exact FK');
check($service->listing(LegacyOfHistory::filters(['article'=>'20','origin'=>'Bobinas']))['total']===2,'Consultation by article exact FK');
check($service->listing(LegacyOfHistory::filters(['q'=>'MESMO-NUMERO']))['total']===2,'Unified search preserves same operational/historical number');
check($service->listing(LegacyOfHistory::filters(['year'=>'2020','origin'=>'Bobinas']))['total']===1,'Year and origin filters');
check($service->listing(LegacyOfHistory::filters(['year'=>'2026','origin'=>'GesTISSER']))['total']===1,'Operational origin remains isolated');
check(count($service->indicators(20))===2,'Unknown quantity units stay separate in BI preparation');
// Constraint failure on final detail tests actual rollback without writing triggers.
$pdo->exec('CREATE UNIQUE INDEX test_failure ON erp_legacy_of_cost_details(registered_time)');
$new=$batch;$new['ofs']['rows'][0]['data']['legacy_id']='rollback';$new['of_operacoes']['rows'][0]['data']['legacy_id']='rollback-op';$new['of_operacoes']['rows'][0]['data']['of_legacy_id']='rollback';
$r=$service->validate($new);fails(function()use($service,$new,$r,$root){$service->import($new,$r,'gestisser-dev',1,'IMPORTAR LOTE HISTORICO',$root.'/backups');},'Final insert failure rolls back whole lote');
check($pdo->query('SELECT COUNT(*) FROM erp_legacy_production_orders')->fetchColumn()==2&&$pdo->query('SELECT status FROM erp_legacy_of_batches ORDER BY id DESC LIMIT 1')->fetchColumn()==='failed','Rollback leaves zero partial OFs and retains failure audit');$pdo->exec('DROP INDEX test_failure');
check(snapshot($pdo,$operational)===$baseline,'Operational snapshots still identical after imports/resolution/rollback');
// Financial provenance is mandatory and real/estimated records remain distinguishable.
$costBatch=['ofs'=>input('ofs',['legacy_id'=>'financial','numero_of'=>'COST','cliente_codigo'=>'C2','artigo_codigo'=>'A2','quantidade_produzida'=>'10','unidade'=>'kg','custo_total'=>'15','custo_unitario'=>'1,5','moeda'=>'EUR','tipo_custo'=>'real','origem_custo'=>'Documento antigo','metodo_calculo'=>'Valor registado na fonte'])];
$service->import($costBatch,$service->validate($costBatch),'gestisser-dev',1,'IMPORTAR LOTE HISTORICO',$root.'/backups');$financial=$service->detail(3);
check($financial['summary']['total_cost']==15&&$financial['summary']['unit_cost']==1.5&&$financial['summary']['cost_type']==='real'&&$financial['quantity_unit']==='kg','Provided monetary amounts preserve currency, classification, method and source units');
$pdo->exec('CREATE TABLE erp_legacy_import_map(source_system TEXT,entity_type TEXT,legacy_id TEXT,target_table TEXT);INSERT INTO erp_legacy_import_map VALUES("Bobinas","ofs","mapped-old","erp_production_orders")');
$service=new LegacyOfHistory($pdo);$mapped=$batch;$mapped['ofs']['rows'][0]['data']['legacy_id']='mapped-old';unset($mapped['of_operacoes']);check($service->validate($mapped)['errors']===1,'Existing older operational mapping requires manual reconciliation');
// Thousands of rows and an indexed customer/date query.
$large=['ofs'=>['entity'=>'ofs','filename'=>'synthetic-volume.xlsx','rows'=>[]]];
for($i=0;$i<6656;$i++){$row=$batch['ofs']['rows'][0];$row['line']=$i+2;$row['data']['legacy_id']='volume-'.$i;$row['data']['numero_of']='V-'.$i;$row['data']['unidade']='un';$large['ofs']['rows'][]=$row;}
$t=microtime(true);$r=$service->validate($large);$validationTime=microtime(true)-$t;
$service->import($large,$r,'gestisser-dev',1,'IMPORTAR LOTE HISTORICO',$root.'/backups');
$t=microtime(true);$l=$service->listing(LegacyOfHistory::filters(['customer'=>'10','year'=>'2020','origin'=>'Bobinas','p'=>'10']));$queryTime=microtime(true)-$t;
check($l['total']===6657&&count($l['rows'])===20,'6656 synthetic OFs paginated efficiently');
$plan=$pdo->query('EXPLAIN QUERY PLAN SELECT id FROM erp_legacy_production_orders WHERE gestisser_customer_id=10 AND created_at_legacy>="2020-01-01" AND created_at_legacy<"2021-01-01"')->fetchAll();check(strpos(json_encode($plan),'idx_legacy_of_customer_date')!==false,'Customer/year query uses historical compound index');
echo 'PERFORMANCE validation_6656_seconds='.round($validationTime,4).' consultation_seconds='.round($queryTime,4)."\n";
$pdo->exec('PRAGMA query_only=ON');$changes=$pdo->query('SELECT total_changes()')->fetchColumn();$service->listing(LegacyOfHistory::filters([]));$service->detail(1);$service->indicators(20);check($changes===$pdo->query('SELECT total_changes()')->fetchColumn(),'All consultations work with query_only and no writes');
check(snapshot($pdo,$operational)===$baseline,'Operational regression snapshot identical after volume test');
require_once __DIR__.'/../historical-import/Importer.php';$oldReport=['schema_hash'=>'x','entities'=>['ofs'=>['validas'=>1]],'errors'=>0];check(count(HistoricalImporter::blockers($oldReport,['enabled'=>true,'reviewed_schema_hash'=>'x','entities'=>['ofs']]))>0,'Older importer cannot enable operational historical OF adapter');
$pdo->exec('PRAGMA query_only=OFF');$pdo->exec('CREATE TRIGGER unexpected_history AFTER INSERT ON erp_legacy_production_orders BEGIN UPDATE erp_stock_balances SET quantity=0; END');
fails(function()use($service,$batch,$root){$service->import($batch,$service->validate($batch),'gestisser-dev',1,'IMPORTAR LOTE HISTORICO',$root.'/backups');},'Unreviewed history trigger blocks writes');check(snapshot($pdo,$operational)===$baseline,'Unsafe trigger never fired');
// XLSX backward compatibility, optional columns and unsafe formula rejection.
$file=HistoricalSpreadsheet::workbook([HistoricalSpreadsheet::contracts()['ofs']['columns'],array_values($batch['ofs']['rows'][0]['data'])],'OFs');$parsed=HistoricalSpreadsheet::read($file);check($parsed['entity']==='ofs','Existing 03_ofs header contract reused');unlink($file);
$headers=array_merge(HistoricalSpreadsheet::contracts()['of_operacoes']['columns'],['custo_registado','moeda','tipo_custo','origem_registo']);$file=HistoricalSpreadsheet::workbook([$headers],'Operações');check(HistoricalSpreadsheet::read($file)['entity']==='of_operacoes','Optional monetary fields accepted without changing original headers');unlink($file);
$pdo=null;
function clean($d){foreach(scandir($d) as $n)if($n!=='.'&&$n!=='..'){is_dir($d.'/'.$n)?clean($d.'/'.$n):unlink($d.'/'.$n);}rmdir($d);}clean($root);
echo "All historical OF tests passed on PHP ".PHP_VERSION.". Synthetic data only.\n";
