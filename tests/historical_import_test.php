<?php
require_once __DIR__.'/../historical-import/Importer.php';
function check($condition,$message) { if(!$condition) throw new RuntimeException($message); }
function expectFailure($callback,$message) { try { $callback(); } catch(Throwable $e) { return; } throw new RuntimeException($message); }
$root=sys_get_temp_dir().'/historical-tests-'.bin2hex(random_bytes(8));mkdir($root,0700);
$db=$root.'/database.sqlite';$pdo=new PDO('sqlite:'.$db);$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$pdo->exec('PRAGMA foreign_keys=ON');
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY, name TEXT, is_admin INTEGER); INSERT INTO users VALUES(1,"Admin",1);
CREATE TABLE app_settings(setting_key TEXT PRIMARY KEY,setting_value TEXT,updated_at TEXT);
CREATE TABLE erp_customers(id INTEGER PRIMARY KEY,code TEXT,name TEXT,tax_number TEXT); INSERT INTO erp_customers VALUES(10,"C1","Cliente Um","123"),(11,"C2","Cliente Dois","123");
CREATE TABLE erp_suppliers(id INTEGER PRIMARY KEY,code TEXT,name TEXT,tax_number TEXT); INSERT INTO erp_suppliers VALUES(20,"F1","Fornecedor","987");
CREATE TABLE erp_finished_products(id INTEGER PRIMARY KEY,code TEXT,description TEXT,customer_product_code TEXT);INSERT INTO erp_finished_products VALUES(30,"P1","Artigo","REF1");
CREATE TABLE erp_products(id INTEGER PRIMARY KEY,code TEXT);INSERT INTO erp_products VALUES(31,"P1");
CREATE TABLE erp_raw_materials(id INTEGER PRIMARY KEY,code TEXT,description TEXT);INSERT INTO erp_raw_materials VALUES(40,"MP1","Ráfia");
CREATE TABLE erp_warehouses(id INTEGER PRIMARY KEY,code TEXT); INSERT INTO erp_warehouses VALUES(50,"A1");
CREATE TABLE erp_locations(id INTEGER PRIMARY KEY,warehouse_id INTEGER REFERENCES erp_warehouses(id),code TEXT); INSERT INTO erp_locations VALUES(60,50,"L1");
CREATE TABLE erp_operations(id INTEGER PRIMARY KEY,code TEXT);INSERT INTO erp_operations VALUES(70,"OP1");
CREATE TABLE erp_production_orders(id INTEGER PRIMARY KEY,order_number TEXT UNIQUE NOT NULL,customer_id INTEGER REFERENCES erp_customers(id),product_id INTEGER NOT NULL REFERENCES erp_products(id),finished_product_id INTEGER REFERENCES erp_finished_products(id),planned_quantity REAL NOT NULL,produced_quantity REAL DEFAULT 0,status TEXT,created_at TEXT,due_date TEXT,notes TEXT,created_by INTEGER REFERENCES users(id));
CREATE TABLE erp_production_order_operations(id INTEGER PRIMARY KEY,production_order_id INTEGER NOT NULL REFERENCES erp_production_orders(id),operation_id INTEGER NOT NULL REFERENCES erp_operations(id),sequence_no INTEGER,planned_minutes INTEGER,status TEXT);
CREATE TABLE erp_raw_material_roll_labels(id INTEGER PRIMARY KEY,raw_material_id INTEGER NOT NULL REFERENCES erp_raw_materials(id),entry_number TEXT NOT NULL,supplier_lot TEXT NOT NULL,metres REAL NOT NULL,weight_kg REAL NOT NULL,initial_weight_kg REAL,initial_metres REAL,barcode TEXT NOT NULL UNIQUE,label_date TEXT NOT NULL,validated_by INTEGER REFERENCES users(id),status TEXT,warehouse_id INTEGER REFERENCES erp_warehouses(id),location_id INTEGER REFERENCES erp_locations(id));
CREATE TABLE erp_raw_material_ink_labels(id INTEGER PRIMARY KEY,raw_material_id INTEGER NOT NULL REFERENCES erp_raw_materials(id),entry_number TEXT NOT NULL,supplier_lot TEXT NOT NULL,weight_kg REAL NOT NULL,initial_weight_kg REAL,barcode TEXT NOT NULL UNIQUE,label_date TEXT NOT NULL,validated_by INTEGER REFERENCES users(id),status TEXT,warehouse_id INTEGER REFERENCES erp_warehouses(id),location_id INTEGER REFERENCES erp_locations(id));
CREATE TABLE erp_stock_movements(id INTEGER PRIMARY KEY,movement_number TEXT NOT NULL UNIQUE,movement_date TEXT,movement_type TEXT,item_type TEXT,item_id INTEGER,quantity REAL,weight REAL,lot TEXT,source_type TEXT,reason TEXT,notes TEXT,created_by INTEGER REFERENCES users(id));
CREATE TABLE erp_stock_balances(id INTEGER PRIMARY KEY,physical_qty REAL); INSERT INTO erp_stock_balances VALUES(1,500);');
function input($entity,array $values) {
 $d=array_fill_keys(HistoricalSpreadsheet::contracts()[$entity]['columns'],'');$d=array_replace($d,$values,['importar'=>'1']);return ['entity'=>$entity,'filename'=>$entity.'.xlsx','rows'=>[['line'=>2,'data'=>$d]]];
}
$batch=[
 'ofs'=>input('ofs',['legacy_id'=>'OF1','numero_of'=>'OF-OLD-1','cliente_codigo'=>'C1','artigo_codigo'=>'P1','quantidade_planeada'=>'10','data_criacao'=>'2020-01-01','estado'=>'Concluída']),
 'of_operacoes'=>input('of_operacoes',['legacy_id'=>'OPOP1','of_legacy_id'=>'OF1','numero_of'=>'OF-OLD-1','operacao'=>'OP1','sequencia'=>'10','horas_previstas'=>'1,5','estado'=>'Concluída']),
 'rolos_rafia'=>input('rolos_rafia',['legacy_id'=>'1234','numero_entrada'=>'ENT1','data_entrada'=>'2020-01-01','materia_prima_codigo'=>'MP1','lote_fornecedor'=>'LOT1','peso_inicial_kg'=>'20','peso_atual_kg'=>'10','metros_iniciais'=>'100','metros_atuais'=>'50','warehouse_id'=>'50','location_id'=>'60','estado'=>'AVAILABLE']),
 'movimentos_historicos'=>input('movimentos_historicos',['legacy_id'=>'M1','data_movimento'=>'2020-01-01','tipo_movimento'=>'Entrada','materia_prima_codigo'=>'MP1','quantidade'=>'100','of_numero'=>'OF-OLD-1'])
];
$before=hash_file('sha256',$db);$pdo->exec('PRAGMA query_only=ON');
$report=(new HistoricalValidator($pdo))->validate($batch);
check($report['errors']===0,'Valid batch errors: '.json_encode($report));check(hash_file('sha256',$db)===$before,'Dry-run changed database');
check(!$pdo->query("SELECT 1 FROM sqlite_master WHERE name='erp_legacy_import_runs'")->fetchColumn(),'Dry-run created tables');
check($report['entities']['rolos_rafia']['rows'][0]['data']['barcode']==='R-LEGACY-00001234','Deterministic barcode');
$bad=$batch;$bad['ofs']['rows'][0]['data']['gestisser_customer_id']='11';check((new HistoricalValidator($pdo))->validate($bad)['errors']>0,'Contradictory manual ID accepted');
$bad=$batch;$bad['ofs']['rows'][0]['data']['cliente_codigo']='';$bad['ofs']['rows'][0]['data']['cliente_nome']='Cliente Um';check((new HistoricalValidator($pdo))->validate($bad)['errors']>0,'Name suggestion matched automatically');
$bad=$batch;$bad['ofs']['rows'][0]['data']['cliente_codigo']='';$bad['ofs']['rows'][0]['data']['cliente_nif']='123';check((new HistoricalValidator($pdo))->validate($bad)['errors']>0,'Ambiguous NIF accepted');
$bad=$batch;$bad['movimentos_historicos']['rows'][0]['data']['afeta_stock_atual']='1';check((new HistoricalValidator($pdo))->validate($bad)['errors']>0,'Stock flag accepted');
$bad=$batch;$bad['ofs']['rows'][0]['data']['data_criacao']='2020-02-31';check((new HistoricalValidator($pdo))->validate($bad)['errors']>0,'Invalid date accepted');
$bad=$batch;$bad['ofs']['rows'][0]['data']['cliente_codigo']='NONE';$badReport=(new HistoricalValidator($pdo))->validate($bad);check($badReport['entities']['of_operacoes']['erros']===1,'Invalid parent not propagated');
$pdo->exec('PRAGMA query_only=OFF');HistoricalImporter::setup($pdo);$report=(new HistoricalValidator($pdo))->validate($batch);
check(HistoricalImporter::blockers($report,HistoricalImporter::configuration()),'Default import enabled');
$config=['enabled'=>true,'reviewed_schema_hash'=>$report['schema_hash'],'entities'=>array_keys($batch)];
// Explicit TEST import, only on synthetic fixtures. Real data is never imported.
mkdir($root.'/app/Services',0700,true);copy(__DIR__.'/../app/Services/BackupManager.php',$root.'/app/Services/BackupManager.php');
$run=HistoricalImporter::run($pdo,$batch,$report,$config,1,$root);check($run['inserted']===4,'Incorrect insert count');check($pdo->query('SELECT physical_qty FROM erp_stock_balances')->fetchColumn()==500,'Stock balance changed');
check($pdo->query('SELECT planned_minutes FROM erp_production_order_operations')->fetchColumn()==90,'Hour conversion wrong');
check($pdo->query('PRAGMA foreign_key_check')->fetch()===false,'Invalid FK');
$report=(new HistoricalValidator($pdo))->validate($batch);check($report['errors']===0,'Rerun errors');$run=HistoricalImporter::run($pdo,$batch,$report,$config,1,$root);check($run['inserted']===0 && $run['skipped']===4,'Not idempotent');
$bad=$batch;$bad['rolos_rafia']['rows'][0]['data']['peso_atual_kg']='9';check((new HistoricalValidator($pdo))->validate($bad)['errors']>0,'Changed legacy record silently accepted');
// Inject a constraint failure on the last entity: all earlier inserts and maps must roll back.
$pdo->exec('CREATE UNIQUE INDEX synthetic_failure ON erp_stock_movements(quantity)');
$new=$batch;foreach($new as $entity=>&$in) {$in['rows'][0]['data']['legacy_id'].='NEW'; if($entity==='ofs')$in['rows'][0]['data']['numero_of']='OF-OLD-2';if($entity==='of_operacoes'){$in['rows'][0]['data']['of_legacy_id']='OF1NEW';$in['rows'][0]['data']['numero_of']='OF-OLD-2';}if($entity==='movimentos_historicos')$in['rows'][0]['data']['of_numero']='OF-OLD-2';}unset($in);
$report=(new HistoricalValidator($pdo))->validate($new);check($report['errors']===0,'New batch invalid');$config['reviewed_schema_hash']=$report['schema_hash'];
expectFailure(function()use($pdo,$new,$report,$config,$root){HistoricalImporter::run($pdo,$new,$report,$config,1,$root);},'Transaction failure ignored');
check($pdo->query('SELECT COUNT(*) FROM erp_production_orders')->fetchColumn()==1,'Partial OF persisted');check($pdo->query('SELECT COUNT(*) FROM erp_legacy_import_map')->fetchColumn()==4,'Partial maps persisted');check($pdo->query('SELECT status FROM erp_legacy_import_runs ORDER BY id DESC LIMIT 1')->fetchColumn()==='failed','Failure run not logged');
require_once __DIR__.'/../app/Services/SimpleXlsx.php';
$zipPath=HistoricalSpreadsheet::templateZip();$zip=new ZipArchive();check($zip->open($zipPath)===true,'Templates ZIP invalid');
foreach(HistoricalSpreadsheet::contracts() as $entity=>$contract) {
 $path=$root.'/'.$contract['file'];file_put_contents($path,$zip->getFromName($contract['file']));$parsed=HistoricalSpreadsheet::read($path);check($parsed['entity']===$entity && count($parsed['rows'])===0,'Template roundtrip failed '.$entity);
 $workbook=new ZipArchive();$workbook->open($path);$xml=$workbook->getFromName('xl/worksheets/sheet1.xml');check(strpos($xml,'state="frozen"')!==false && strpos($xml,'autoFilter')!==false,'Freeze/filter missing');$workbook->close();
}
$zip->close();unlink($zipPath);
$contract=HistoricalSpreadsheet::contracts()['ofs'];$path=SimpleXlsx::create([$contract['columns'],array_values($batch['ofs']['rows'][0]['data'])]);$parsed=HistoricalSpreadsheet::read($path);check($parsed['rows'][0]['data']['legacy_id']==='OF1','XLSX row parse failed');
$zip=new ZipArchive();$zip->open($path);$xml=$zip->getFromName('xl/worksheets/sheet1.xml');$zip->addFromString('xl/worksheets/sheet1.xml',str_replace('t="inlineStr"><is><t xml:space="preserve">OF1','t="inlineStr"><f>1+1</f><is><t xml:space="preserve">OF1',$xml));$zip->close();expectFailure(function()use($path){HistoricalSpreadsheet::read($path);},'Formula accepted');unlink($path);
$path=SimpleXlsx::create([$contract['columns']]);$zip=new ZipArchive();$zip->open($path);$zip->addFromString('evil.xml','<!DOCTYPE x [<!ENTITY x SYSTEM "file:///etc/passwd">]><x>&x;</x>');$zip->close();expectFailure(function()use($path){HistoricalSpreadsheet::read($path);},'XXE accepted');unlink($path);
$pdo=null;
function removeTree($dir) { foreach(scandir($dir) as $name) {if($name==='.'||$name==='..')continue;$path=$dir.'/'.$name;if(is_dir($path))removeTree($path);else unlink($path);}rmdir($dir); }
removeTree($root);echo "PASS historical import: read-only dry-run, matching, dependency errors, deterministic barcodes, backup, atomic rollback, idempotency, XLSX and unsafe XML rejection\n";
