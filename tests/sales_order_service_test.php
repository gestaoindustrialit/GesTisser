<?php
require_once dirname(__DIR__).'/bootstrap/php70_polyfills.php';
require_once dirname(__DIR__).'/app/Services/SalesOrderService.php';
require_once dirname(__DIR__).'/app/Services/SalesOrderHistoryPreparation.php';
function check($yes,$label){if(!$yes)throw new RuntimeException($label);echo "PASS: $label\n";}
function rejected($pdo,$call,$label){$pdo->beginTransaction();try{$call();$pdo->rollBack();throw new Exception('Not rejected: '.$label);}catch(RuntimeException $e){if($pdo->inTransaction())$pdo->rollBack();echo "PASS: $label\n";}}
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$db->exec('PRAGMA foreign_keys=ON');
foreach([
'users'=>'id INTEGER PRIMARY KEY',
'erp_customers'=>'id INTEGER PRIMARY KEY,code TEXT,name TEXT,is_active INTEGER',
'erp_units'=>'id INTEGER PRIMARY KEY,code TEXT',
'erp_finished_products'=>'id INTEGER PRIMARY KEY,code TEXT,description TEXT,customer_id INTEGER,unit_id INTEGER,status TEXT,standard_price REAL',
'erp_number_sequences'=>'id INTEGER PRIMARY KEY,code TEXT UNIQUE,prefix TEXT,next_number INTEGER,padding INTEGER,suffix TEXT,updated_at TEXT',
'erp_production_orders'=>'id INTEGER PRIMARY KEY,customer_id INTEGER,finished_product_id INTEGER,order_number TEXT,planned_quantity REAL,produced_quantity REAL,status TEXT,due_date TEXT',
'erp_stock_movements'=>'id INTEGER PRIMARY KEY,item_type TEXT,item_id INTEGER,movement_type TEXT,quantity REAL,reversal_of_id INTEGER,movement_number TEXT,movement_date TEXT',
'erp_stock_balances'=>'id INTEGER PRIMARY KEY,physical_qty REAL',
'erp_audit_log'=>'id INTEGER PRIMARY KEY,user_id INTEGER,action TEXT,entity TEXT,entity_id INTEGER,old_values_json TEXT,new_values_json TEXT,reason TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP',
'erp_production_order_closures'=>'production_order_id INTEGER,total_planned_cost REAL,total_actual_cost REAL,closed_at TEXT',
'erp_product_documents'=>'id INTEGER PRIMARY KEY,entity_type TEXT,entity_id INTEGER,title TEXT,document_type TEXT,version TEXT,status TEXT,file_url TEXT',
'erp_legacy_import_map'=>'source_system TEXT,target_table TEXT,legacy_id TEXT,gestisser_id INTEGER,source_hash TEXT'
] as $table=>$columns)$db->exec('CREATE TABLE '.$table.'('.$columns.')');
$db->exec("INSERT INTO users VALUES(1);INSERT INTO erp_customers VALUES(1,'C1','Mesmo nome',1),(2,'C2','Mesmo nome',1),(3,'C3','Inativo',0);INSERT INTO erp_units VALUES(1,'kg'),(2,'un');INSERT INTO erp_finished_products VALUES(1,'A%_1','Artigo kg',1,1,'Ativo',999),(2,'A2','Artigo un',1,2,'Ativo',888),(3,'A3','Outro cliente',2,1,'Ativo',777),(4,'A4','Inativo',3,1,'Inativo',99),(5,'A5','Sem unidade',1,NULL,'Ativo',1);INSERT INTO erp_stock_balances VALUES(1,100);");
SalesOrderSchema::migrate($db);SalesOrderSchema::migrate($db);
check((int)$db->query('SELECT COUNT(*) FROM erp_number_sequences')->fetchColumn()===1,'Migration and numbering seed are idempotent');
$s=new SalesOrderService($db);
$input=['customer_id'=>'1','order_date'=>'2026-01-01','expected_date'=>'2026-01-10','status'=>'Confirmada','customer_reference'=>'Original','lines'=>[['finished_product_id'=>'1','quantity'=>'100','unit_price'=>'2.50','discount_percent'=>'10'],['finished_product_id'=>'2','quantity'=>'10','unit_price'=>'3'],['finished_product_id'=>'5','quantity'=>'5']]];
$db->beginTransaction();$id=$s->save($input,1,true);$db->commit();$o=$s->order($id);$ls=$s->lines($id);
check($o['order_number']==='EC-00001'&&count($ls)===3,'Separate commercial number; multi-line order without OF');
check((float)$ls[0]['line_total']===225.0&&(float)$ls[1]['line_total']===30.0&&$ls[2]['line_total']===null,'Recorded commercial prices, discounts and absent values');
check(count(SalesOrderService::totals($ls,'quantity'))===3,'Mixed and unknown units never aggregated together');
check($ls[0]['delivered']===0&&$ls[0]['pending']===100.0,'No production or delivery inferred');
rejected($db,function()use($s,$input){$s->save($input,1,false);},'Confirmation permission enforced');
$wrong=$input;$wrong['lines'][0]['finished_product_id']='3';rejected($db,function()use($s,$wrong){$s->save($wrong,1,true);},'Foreign-customer article rejected despite equal customer names');
$bad=$input;$bad['order_date']='2026-02-30';rejected($db,function()use($s,$bad){$s->save($bad,1,true);},'Invalid calendar date rejected');
$bad=$input;$bad['lines'][0]['quantity']='-1';rejected($db,function()use($s,$bad){$s->save($bad,1,true);},'Negative quantity rejected');
$bad=$input;$bad['lines'][0]['discount_percent']='101';rejected($db,function()use($s,$bad){$s->save($bad,1,true);},'Discount bounded');
$edit=$input;$edit['id']=(string)$id;$edit['revision']='1';foreach($ls as $i=>$l){$edit['lines'][$i]['id']=(string)$l['id'];}
$db->exec('UPDATE erp_finished_products SET standard_price=9999 WHERE id=1');
$db->beginTransaction();$s->save($edit,1,true);$db->commit();check((float)$s->lines($id)[0]['line_total']===225.0,'Editing preserves prices and totals despite catalogue price changes');
rejected($db,function()use($s,$edit){$s->save($edit,1,true);},'Concurrent stale revision rejected');
$db->exec("INSERT INTO erp_production_orders VALUES(1,1,1,'OF1',60,60,'Fechada','2026-01-05'),(2,1,1,'OF2',40,20,'Em produção','2026-01-07'),(3,2,1,'OF3',5,5,'Fechada',NULL);");
$before=$db->query('SELECT * FROM erp_production_orders')->fetchAll();
$db->beginTransaction();$s->linkWorkOrder($id,$ls[0]['id'],1,1);$s->linkWorkOrder($id,$ls[0]['id'],2,1);$db->commit();
$l=$s->lines($id)[0];check($l['of_count']===2&&$l['produced']===80.0&&$l['planned']===100.0&&$l['delivered']===0,'Several OF per line; partial production and closed OF never imply delivery');
check($db->query('SELECT * FROM erp_production_orders')->fetchAll()===$before,'Existing OF unchanged');
rejected($db,function()use($s,$id,$ls){$s->linkWorkOrder($id,$ls[0]['id'],3,1);},'Cross-customer OF association rejected');
rejected($db,function()use($s,$id,$ls){$s->linkWorkOrder($id,$ls[1]['id'],1,1);},'Cross-article OF association rejected');
$db->exec("INSERT INTO erp_stock_movements VALUES(1,'finished_product',1,'Saída',-30,NULL,'MOV1','2026-01-02'),(2,'finished_product',2,'Saída',5,NULL,'MOV2','2026-01-02'),(3,'finished_product',1,'Entrada',30,NULL,'MOV3','2026-01-02');");$movements=$db->query('SELECT * FROM erp_stock_movements')->fetchAll();
$db->beginTransaction();$s->linkDelivery($id,$ls[0]['id'],1,'Guia comprovada',1);$db->commit();$l=$s->lines($id)[0];check($l['delivered']===30.0&&$l['pending']===70.0&&$l['produced']===80.0,'Partial delivery counted separately using existing ledger');
check($movements===$db->query('SELECT * FROM erp_stock_movements')->fetchAll()&&(float)$db->query('SELECT physical_qty FROM erp_stock_balances')->fetchColumn()===100.0,'No stock, ledger or balance mutations');
rejected($db,function()use($s,$id,$ls){$s->linkDelivery($id,$ls[1]['id'],1,'Guia',1);},'Delivery article validated');
rejected($db,function()use($s,$id,$ls){$s->linkDelivery($id,$ls[0]['id'],3,'Guia',1);},'Entry cannot be a delivery');
rejected($db,function()use($s,$id,$ls){$s->linkDelivery($id,$ls[0]['id'],1,'Guia',1);},'Same outgoing movement cannot be counted twice');
$db->exec("INSERT INTO erp_stock_movements VALUES(4,'finished_product',1,'Entrada',30,1,'REV','2026-01-03')");check($s->lines($id)[0]['delivered']===0,'Reversed outgoing movement excluded; trace remains');
check($s->listing(SalesOrderService::filters(['q'=>'%_']))['total']===1,'Search wildcards literal');
check($s->listing(SalesOrderService::filters(['customer'=>'2']))['total']===0,'Exact customer filter');
check($s->listing(SalesOrderService::filters(['late'=>'1']))['total']===1,'Late filter uses undelivered line quantities and dates');
check($s->listing(SalesOrderService::filters(['from'=>'2026-02-01']))['total']===0,'Date range filtering');
$db->exec("UPDATE erp_sales_orders SET status='Cancelada' WHERE id=1");check($s->listing(SalesOrderService::filters(['late'=>'1']))['total']===0,'Cancelled orders excluded from overdue');
rejected($db,function()use($s,$id,$ls){$s->linkWorkOrder($id,$ls[0]['id'],3,1);},'Cancelled order rejects associations');
$db->exec("INSERT INTO erp_legacy_import_map VALUES('Bobinas','erp_customers','OLD-C',3,NULL),('Bobinas','erp_finished_products','OLD-A',4,NULL)");
$historical=['original_id'=>'OLD1','original_number'=>'1998/001','customer_original_id'=>'OLD-C','order_date'=>'1998-01-01','original_status'=>'Fechada antiga','lines'=>[['article_original_id'=>'OLD-A','original_code'=>'XX','quantity'=>4,'original_of'=>'OF antiga']]];
$changes=$db->query('SELECT total_changes()')->fetchColumn();$db->exec('PRAGMA query_only=ON');$report=SalesOrderHistoryPreparation::simulate($db,[$historical]);check(count($report['ready'])===1&&$report['ready'][0]['customer_id']===3&&$report['ready'][0]['lines'][0]['finished_product_id']===4,'Historical preparation includes inactive entities via validated mappings');
check(strpos($report['ready'][0]['original_data_json'],'OF antiga')!==false,'Original payload, status, codes and OF references retained');
$db->exec('PRAGMA query_only=OFF');$prepared=$report['ready'][0];
$db->prepare('INSERT INTO erp_sales_orders(order_number,customer_id,order_date,source_system,original_id,original_data_json) VALUES(?,3,"1998-01-01","Bobinas",?,?)')->execute([$prepared['original_number'],$prepared['original_id'],$prepared['original_data_json']]);$legacyId=$db->lastInsertId();
$db->prepare('INSERT INTO erp_legacy_import_map VALUES("Bobinas","erp_sales_orders",?,?,?)')->execute([$prepared['original_id'],$legacyId,$prepared['source_hash']]);$changes=$db->query('SELECT total_changes()')->fetchColumn();$db->exec('PRAGMA query_only=ON');
check(count(SalesOrderHistoryPreparation::simulate($db,[$historical])['unchanged'])===1,'Repeat historical source is idempotent when mapping and payload agree');
$changedSource=$historical;$changedSource['original_status']='Alterado';check(count(SalesOrderHistoryPreparation::simulate($db,[$changedSource])['conflicts'])===1,'Changed historical source reported without overwriting');
check(count(SalesOrderHistoryPreparation::simulate($db,[$historical,$historical])['conflicts'])===1,'Duplicate historical IDs reported');
$unresolved=$historical;$unresolved['customer_original_id']='missing';check(count(SalesOrderHistoryPreparation::simulate($db,[$unresolved])['conflicts'])===1,'Missing mapping reported; never create duplicate master data');
$s->listing(SalesOrderService::filters([]));$s->history($id);$s->costs($id);$s->documents($id);
check($changes==$db->query('SELECT total_changes()')->fetchColumn(),'Consultation and simulation are read-only under SQLite query_only');$db->exec('PRAGMA query_only=OFF');
$db->exec("INSERT INTO erp_sales_orders(id,order_number,customer_id,order_date,source_system,original_id,status) VALUES(100,'1999/1',3,'1999-01-01','Bobinas','O2','Estado antigo');INSERT INTO erp_sales_order_lines(sales_order_id,line_number,finished_product_id,article_code,description,quantity,unit_id,unit_code) VALUES(100,1,4,'Original','Original',4,1,'kg')");
check($s->order(100)['status']==='Estado antigo'&&count($s->lines(100))===1,'Old statuses and inactive customer/article remain consultable');
rejected($db,function()use($s){$s->save(['id'=>100],1,true);},'Historical records immutable');
for($i=3;$i<28;$i++)$db->exec("INSERT INTO erp_sales_orders(order_number,customer_id,order_date,status) VALUES('TEST-$i',1,'2026-01-01','Rascunho')");
$list=$s->listing(SalesOrderService::filters(['p'=>'9999','sort'=>'id;DROP TABLE users']));check($list['pages']===2&&$list['page']===2&&count($list['rows'])===8,'Pagination clamped; SQL sort injection rejected');
check(count($db->query('PRAGMA foreign_key_check')->fetchAll())===0,'Foreign key integrity');
echo "Sales orders: all checks passed.\n";
