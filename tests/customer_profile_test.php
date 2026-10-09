<?php
require_once dirname(__DIR__).'/bootstrap/php70_polyfills.php';
require_once dirname(__DIR__).'/app/Services/CustomerProfile.php';
function check($condition,$message) {if(!$condition)throw new RuntimeException($message);echo "PASS: $message\n";}
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$schema=[
'erp_customers'=>'id INTEGER PRIMARY KEY,code TEXT,name TEXT',
'erp_customer_delivery_addresses'=>'id INTEGER,customer_id INTEGER,label TEXT',
'erp_units'=>'id INTEGER PRIMARY KEY,code TEXT',
'erp_finished_products'=>'id INTEGER PRIMARY KEY,customer_id INTEGER,code TEXT,description TEXT,unit_id INTEGER',
'erp_products'=>'id INTEGER PRIMARY KEY,code TEXT,description TEXT,unit_id INTEGER',
'erp_production_orders'=>'id INTEGER PRIMARY KEY,customer_id INTEGER,finished_product_id INTEGER,product_id INTEGER,order_number TEXT,created_at TEXT,due_date TEXT,status TEXT,planned_quantity REAL,produced_quantity REAL',
'erp_production_order_operations'=>'id INTEGER,production_order_id INTEGER,operation_id INTEGER,sequence_no INTEGER',
'erp_operations'=>'id INTEGER,name TEXT',
'erp_operation_time_entries'=>'production_order_operation_id INTEGER,started_at TEXT,ended_at TEXT,pause_seconds REAL',
'erp_production_order_costs'=>'production_order_id INTEGER,category TEXT,actual_amount REAL',
'erp_production_order_closures'=>'production_order_id INTEGER,total_actual_cost REAL,closed_at TEXT',
'erp_legacy_import_map'=>'source_system TEXT,target_table TEXT,gestisser_id INTEGER,legacy_id TEXT',
'erp_production_consumptions'=>'id INTEGER,production_order_id INTEGER,quantity REAL,unit_code TEXT,lot TEXT,created_at TEXT,stock_unit_type TEXT,stock_unit_id INTEGER,source_movement_id INTEGER,raw_material_id INTEGER',
'erp_raw_materials'=>'id INTEGER,code TEXT,description TEXT',
'erp_stock_movements'=>'id INTEGER,movement_number TEXT',
'erp_raw_material_ink_labels'=>'id INTEGER,barcode TEXT,supplier_lot TEXT',
'erp_raw_material_roll_consumptions'=>'id INTEGER,production_order_id INTEGER,source_label_id INTEGER,consumed_metres REAL,consumed_weight_kg REAL,created_at TEXT',
'erp_raw_material_roll_labels'=>'id INTEGER,barcode TEXT,supplier_lot TEXT'
];
foreach($schema as $table=>$columns)$db->exec('CREATE TABLE '.$table.'('.$columns.')');
$db->exec("INSERT INTO erp_customers VALUES(1,'C1','Igual'),(2,'C2','Igual'),(3,'C3','Vazio');
INSERT INTO erp_units VALUES(1,'kg'),(2,'un');
INSERT INTO erp_finished_products VALUES(1,1,'A%_1','Artigo A',1),(2,2,'B','Artigo B',2),(3,2,'C','Artigo C',NULL),(4,1,'D','Só catálogo',1);
INSERT INTO erp_products VALUES(1,'LEGACY','Legado',NULL);
INSERT INTO erp_production_orders VALUES(1,1,1,NULL,'OF1','2025-01-01',NULL,'Fechada',20,10),(2,1,2,NULL,'OF2','2026-01-01',NULL,'Fechada',20,5),(3,2,1,NULL,'OUTRO','2026-01-01',NULL,'Fechada',99,99),(4,1,3,NULL,'OF4','2026-01-01',NULL,'Fechada',8,8),(5,1,NULL,1,'OF5','2026-01-01',NULL,'Fechada',7,7);
INSERT INTO erp_production_order_operations VALUES(1,1,1,1),(2,3,1,1);
INSERT INTO erp_operations VALUES(1,'Impressão');
INSERT INTO erp_operation_time_entries VALUES(1,'2025-01-01 08:00','2025-01-01 10:00',1800),(1,'2025-01-02 08:00',NULL,0),(2,'2026-01-01 08:00','2026-01-01 12:00',0);
INSERT INTO erp_production_order_costs VALUES(1,'labor',12),(1,'labor',8),(3,'labor',999);
INSERT INTO erp_production_order_closures VALUES(1,30,'2025-01-02');
INSERT INTO erp_legacy_import_map VALUES('Bobinas','erp_production_orders',1,'OLD1'),('Bobinas','erp_production_orders',1,'OLD1');
INSERT INTO erp_raw_materials VALUES(1,'M1','Tinta');
INSERT INTO erp_stock_movements VALUES(1,'MOV1');
INSERT INTO erp_raw_material_ink_labels VALUES(1,'INK1','LOTE1');
INSERT INTO erp_production_consumptions VALUES(1,1,2,'kg','LOTE1','2025-01-01','INK',1,1,1),(2,3,99,'kg','OUTRO','2025-01-01','INK',1,1,1);
INSERT INTO erp_raw_material_roll_labels VALUES(1,'ROLL1','RAFIA1');
INSERT INTO erp_raw_material_roll_consumptions VALUES(1,1,1,20,2,'2025-01-01');
INSERT INTO erp_customer_delivery_addresses VALUES(1,1,'Destino'),(2,2,'Outro');");
$before=$db->query('SELECT total_changes()')->fetchColumn();$db->exec('PRAGMA query_only=ON');
$service=new CustomerProfile($db);$f=CustomerProfile::filters([]);$s=$service->summary(1);
check($s['ofs']===4&&$s['produced_articles']===3,'OFs scoped to exact customer FK; no matching by name or article owner');
check($s['orders']===null&&$s['last_order']===null,'Purchases never counted as sales');
check(count($s['quantities'])===4,'Mixed and unknown units kept separate');
check(abs($s['hours']-1.5)<0.000001,'Closed times subtract pauses; open and other-customer entries excluded');
check($service->summary(3)['hours']===null&&$service->summary(3)['ofs']===0,'Empty customer distinguishes absent data and zero OFs');
$list=$service->orders(1,$f);check($list['total']===4&&count($list['rows'])===4,'Legacy mapping duplicates cannot multiply OFs');
$filtered=$service->orders(1,CustomerProfile::filters(['year'=>'2025','status'=>'Fechada','article'=>'1']));check($filtered['total']===1&&$filtered['rows'][0]['legacy_id']==='OLD1','Year, status, article and proven historical origin');
$articles=$service->articles(1,$f);check($articles['total']===4,'Articles use current customer FK or exact historical OF relation');
$search=$service->articles(1,CustomerProfile::filters(['q'=>'%_']));check($search['total']===1&&$search['rows'][0]['id']==1,'Search treats SQL wildcards literally');
$c=$service->costs(1,$f);check($c['costs'][1][0]['amount']==20&&$c['closures'][1]['total_actual_cost']==30&&!isset($c['costs'][3]),'Persisted costs only; no tariffs or foreign customer data');
$t=$service->trace(1,$f);check(count($t['consumptions'][1])===1&&$t['consumptions'][1][0]['ink_barcode']==='INK1'&&$t['rolls'][1][0]['barcode']==='ROLL1','Trace follows explicit material, movement, ink and roll identifiers');
check(count($service->addresses(1))===1,'Delivery addresses preserve customer scope');
check(count($service->frequent(1))===3,'Frequent produced articles use exact customer OF relation');
check(count($service->options(1)['years'])===2,'Filter choices scoped to customer');
$bad=CustomerProfile::filters(['year'=>['2025'],'status'=>['x'],'q'=>['x'],'p'=>'999999','sort'=>'id;DROP TABLE erp_customers']);check($bad['year']===''&&$bad['status']===''&&$bad['q']===''&&$bad['p']===10000&&$bad['sort']==='newest','Malformed filters rejected and pagination bounded');
check($before==$db->query('SELECT total_changes()')->fetchColumn(),'All service queries run with SQLite query_only; zero writes');
$db->exec('PRAGMA query_only=OFF');$insert=$db->prepare('INSERT INTO erp_production_orders(id,customer_id,order_number,created_at,status,produced_quantity) VALUES(?,1,?,"2026-01-01","Fechada",0)');for($i=10;$i<35;$i++)$insert->execute([$i,'OF'.$i]);$db->exec('PRAGMA query_only=ON');
$list=$service->orders(1,CustomerProfile::filters(['p'=>'9999']));check($list['total']===29&&$list['pages']===2&&$list['page']===2&&count($list['rows'])===9,'Long production lists paginated and out-of-range page clamped');
echo "Customer profile queries: all checks passed.\n";
