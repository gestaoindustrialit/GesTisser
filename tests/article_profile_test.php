<?php
require_once dirname(__DIR__).'/bootstrap/php70_polyfills.php';
require_once dirname(__DIR__).'/app/Services/ArticleProfile.php';
function ap_check($condition,$message) {if(!$condition)throw new RuntimeException($message);echo "PASS: $message\n";}
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$schema=[
'erp_customers'=>'id INTEGER PRIMARY KEY,code TEXT,name TEXT',
'erp_units'=>'id INTEGER PRIMARY KEY,code TEXT',
'erp_finished_products'=>'id INTEGER PRIMARY KEY,customer_id INTEGER,code TEXT,description TEXT,unit_id INTEGER',
'erp_products'=>'id INTEGER PRIMARY KEY,code TEXT,description TEXT,unit_id INTEGER',
'erp_production_orders'=>'id INTEGER PRIMARY KEY,customer_id INTEGER,finished_product_id INTEGER,product_id INTEGER,order_number TEXT,created_at TEXT,due_date TEXT,status TEXT,planned_quantity REAL,produced_quantity REAL',
'erp_production_order_operations'=>'id INTEGER PRIMARY KEY,production_order_id INTEGER,operation_id INTEGER,sequence_no INTEGER,operation_name TEXT,snapshot_json TEXT,status TEXT,planned_minutes REAL',
'erp_operations'=>'id INTEGER PRIMARY KEY,name TEXT',
'erp_operation_time_entries'=>'production_order_operation_id INTEGER,started_at TEXT,ended_at TEXT,pause_seconds REAL,quantity_good REAL',
'erp_production_order_costs'=>'production_order_id INTEGER,category TEXT,planned_amount REAL,actual_amount REAL',
'erp_production_order_closures'=>'production_order_id INTEGER,total_actual_cost REAL,total_planned_cost REAL,closed_at TEXT,metrics_json TEXT',
'erp_production_order_routing_snapshots'=>'production_order_id INTEGER,total_planned_minutes REAL',
'erp_legacy_import_map'=>'source_system TEXT,target_table TEXT,gestisser_id INTEGER,legacy_id TEXT',
'erp_production_consumptions'=>'id INTEGER PRIMARY KEY,production_order_id INTEGER,quantity REAL,unit_code TEXT,lot TEXT,created_at TEXT,stock_unit_type TEXT,stock_unit_id INTEGER,source_movement_id INTEGER,raw_material_id INTEGER,unit_cost REAL',
'erp_raw_materials'=>'id INTEGER PRIMARY KEY,code TEXT,description TEXT,primary_unit_id INTEGER',
'erp_stock_movements'=>'id INTEGER PRIMARY KEY,movement_number TEXT,movement_date TEXT,movement_type TEXT,item_type TEXT,item_id INTEGER,lot TEXT,quantity REAL',
'erp_raw_material_ink_labels'=>'id INTEGER PRIMARY KEY,barcode TEXT,supplier_lot TEXT',
'erp_raw_material_roll_consumptions'=>'id INTEGER PRIMARY KEY,production_order_id INTEGER,source_label_id INTEGER,consumed_metres REAL,consumed_weight_kg REAL,created_at TEXT',
'erp_raw_material_roll_labels'=>'id INTEGER PRIMARY KEY,barcode TEXT,supplier_lot TEXT',
'erp_product_documents'=>'id INTEGER PRIMARY KEY,entity_type TEXT,entity_id INTEGER,document_type TEXT,title TEXT,file_url TEXT,version TEXT,status TEXT',
'erp_technical_sheets'=>'id INTEGER PRIMARY KEY,production_order_id INTEGER,finished_product_id INTEGER,created_at TEXT',
'erp_article_technical_sheet_versions'=>'id INTEGER PRIMARY KEY,finished_product_id INTEGER,version_no INTEGER,status TEXT,effective_from TEXT,created_at TEXT,snapshot_json TEXT',
'erp_article_routings'=>'id INTEGER PRIMARY KEY,finished_product_id INTEGER,name TEXT',
'erp_article_routing_versions'=>'id INTEGER PRIMARY KEY,routing_id INTEGER,version_no INTEGER,status TEXT,effective_from TEXT,created_at TEXT',
'erp_article_routing_steps'=>'id INTEGER PRIMARY KEY,routing_version_id INTEGER,operation_id INTEGER,work_center_id INTEGER,primary_machine_id INTEGER,sort_order INTEGER',
'erp_work_centers'=>'id INTEGER PRIMARY KEY,name TEXT',
'erp_machines'=>'id INTEGER PRIMARY KEY,name TEXT',
'erp_routing_step_materials'=>'id INTEGER PRIMARY KEY,routing_step_id INTEGER,material_id INTEGER,quantity_per_unit REAL',
'erp_article_materials'=>'id INTEGER PRIMARY KEY,finished_product_id INTEGER,raw_material_id INTEGER,quantity_per_unit REAL',
'erp_product_colors'=>'id INTEGER PRIMARY KEY,finished_product_id INTEGER,color_id INTEGER,color_order INTEGER,face TEXT',
'erp_colors'=>'id INTEGER PRIMARY KEY,name TEXT',
'erp_product_features'=>'id INTEGER PRIMARY KEY,finished_product_id INTEGER,feature_key TEXT,feature_value TEXT'
];
foreach($schema as $table=>$columns)$db->exec('CREATE TABLE '.$table.'('.$columns.')');
$db->exec("INSERT INTO erp_customers VALUES(1,'C1','Mesmo nome'),(2,'C2','Mesmo nome');
INSERT INTO erp_units VALUES(1,'un'),(2,'kg');
INSERT INTO erp_finished_products VALUES(1,1,'A','Artigo A',1),(2,1,'B','Artigo B',2),(3,NULL,'EMPTY','Vazio',NULL);
INSERT INTO erp_production_orders VALUES(1,1,1,NULL,'OF1','2025-01-01',NULL,'Fechada',20,10),(2,2,1,NULL,'OF2','2026-01-02',NULL,'Fechada',30,20),(3,1,2,NULL,'OTHER','2026-01-02',NULL,'Fechada',99,99);
INSERT INTO erp_production_order_operations VALUES(1,1,999,1,'Operação removida',NULL,'Concluída',90),(2,2,998,1,NULL,'{\"name\":\"Nome no snapshot\"}','Concluída',90),(3,3,1,1,'Outro artigo',NULL,'Concluída',99);
INSERT INTO erp_operation_time_entries VALUES(1,'2025-01-01 08:00','2025-01-01 10:00',1800,10),(1,'2025-01-02 08:00',NULL,0,0),(2,'2026-01-02 08:00','2026-01-02 09:00',0,20),(3,'2026-01-02 08:00','2026-01-02 20:00',0,99);
INSERT INTO erp_production_order_closures VALUES(1,30,20,'2025-01-03','{}'),(2,50,40,'2026-01-03','{}'),(3,999,999,'2026-01-03','{}');
INSERT INTO erp_production_order_costs VALUES(1,'labor',5,10),(1,'labor',5,10),(3,'labor',999,999);
INSERT INTO erp_production_order_routing_snapshots VALUES(1,90);
INSERT INTO erp_legacy_import_map VALUES('Bobinas','erp_production_orders',1,'OLD1'),('Bobinas','erp_production_orders',1,'OLD1');
INSERT INTO erp_raw_materials VALUES(1,'M1','Tinta',2);
INSERT INTO erp_raw_material_ink_labels VALUES(1,'INK1','L1'),(2,'INK2','L1');
INSERT INTO erp_production_consumptions VALUES(1,1,2,'kg','L1','2025-01-01','INK',1,NULL,1,5),(2,3,3,'kg','L1','2026-01-01','INK',1,NULL,1,5),(3,2,2,'kg','L1','2026-01-01','INK',2,NULL,1,5);
INSERT INTO erp_raw_material_roll_labels VALUES(1,'ROLL1','RAF1');
INSERT INTO erp_raw_material_roll_consumptions VALUES(1,1,1,20,2,'2025-01-01');
INSERT INTO erp_stock_movements VALUES(1,'MOVE_EMPTY','2026-01-01','Entrada','finished_product',3,'LOT',7);
INSERT INTO erp_product_documents VALUES(1,'finished_product',1,'production_main','Maquete','x.pdf','1','Ativo'),(2,'finished_product',2,'drawing','Outro','y.pdf','1','Ativo');
INSERT INTO erp_article_technical_sheet_versions VALUES(1,1,1,'approved','2025-01-01','2025-01-01','{\"width\":42}'),(2,2,1,'approved','2025-01-01','2025-01-01','{}');
INSERT INTO erp_article_routings VALUES(1,1,'Roteiro'),(2,2,'Outro roteiro');
INSERT INTO erp_article_routing_versions VALUES(1,1,1,'active','2025-01-01','2025-01-01'),(2,2,1,'active','2025-01-01','2025-01-01'),(3,1,2,'active','2099-01-01','2026-01-01');");
$before=$db->query('SELECT total_changes()')->fetchColumn();$db->exec('PRAGMA query_only=ON');
$service=new ArticleProfile($db);$f=ArticleProfile::filters([]);$s=$service->summary(1,true);
ap_check($s['ofs']===2&&$s['quantity']===30.0,'Exact article FK includes both customers, excludes other articles');
ap_check($s['orders']===null,'Purchases never presented as customer orders');
ap_check(abs($s['hours']-2.5)<.00001&&$s['last_production']==='2026-01-03','Completed time subtracts pauses and excludes open entries; production dates use evidence');
ap_check($s['average_cost']===40.0&&$s['closed_ofs']===2,'Average uses unique recorded closure totals, no duplicated detail costs');
ap_check($service->summary(1,false)['average_cost']===null,'No financial query result without financial permission');
$empty=$service->summary(3,true);ap_check($empty['ofs']===0&&$empty['quantity']===null&&$empty['hours']===null&&$empty['average_cost']===null,'Absent history stays unknown; verified OF count is zero');
$list=$service->history(1,$f,'costs');ap_check($list['total']===2&&count($list['rows'])===2,'Duplicate legacy maps cannot multiply history');
ap_check($list['operations'][1][0]['name']==='Operação removida'&&$list['operations'][2][0]['name']==='Nome no snapshot','Removed operation catalogue retains historical names and snapshot');
ap_check($list['costs'][1][0]['actual']==20&&$list['closures'][1]['total_actual_cost']==30&&$list['planned'][1]===1.5,'Categories distinct from closure totals; planned time from immutable routing snapshot');
$list=$service->history(1,ArticleProfile::filters(['from'=>'2025-01-01','to'=>'2025-01-01','status'=>'Fechada']));ap_check($list['total']===1,'Inclusive date range and status apply to scoped history');
$trace=$service->history(1,$f,'trace');ap_check(count($trace['consumptions'][1])===1&&count($trace['consumptions'][2])===1&&!isset($trace['consumptions'][3]),'Trace cannot leak other article consumption into its production history');
$reverse=$service->reverseTrace(1,'INK',1);ap_check(count($reverse)===2,'Reverse trace finds articles using same proven stock unit');
ap_check(count($service->reverseTrace(1,'INK',999))===0&&count($service->reverseTrace(3,'INK',1))===0,'Unknown or unrelated identifiers cannot manufacture reverse relations');
ap_check(count($service->reverseTrace(1,'INK',2))===1,'Identical lot names on distinct units do not merge traceability');
ap_check($service->movements(3,$f)['total']===1,'Stock movements remain visible without any OF');
ap_check(count($service->documents(1))===1&&$service->documents(1)[0]['document_type']==='production_main','Documents and current artwork use existing entity IDs and document type');
ap_check($service->technicalVersion(1,1)['snapshot_json']==='{"width":42}'&&$service->technicalVersion(1,2)===null,'Technical snapshots scoped to article; foreign versions rejected');
ap_check((int)$service->routing(1,2)['selected']['id']===1&&(int)$service->routing(1)['active']['id']===1,'Foreign version rejected; future active version not yet effective');
ap_check($service->routing(3)['active']===null&&$service->article(3)['customer_id']===null,'Article without routing and customer supported');
$bad=ArticleProfile::filters(['from'=>'2026-02-30','to'=>['2026-01-01'],'status'=>['x'],'p'=>'999999','sort'=>'DROP','article'=>'2']);ap_check($bad['from']===''&&$bad['to']===''&&$bad['p']===10000&&$bad['sort']==='newest'&&$bad['article']===0,'Malformed dates and filters rejected; article scope cannot be changed');
ap_check($before==$db->query('SELECT total_changes()')->fetchColumn(),'All consultations run under query_only with zero writes');
$db->exec('PRAGMA query_only=OFF');$insert=$db->prepare('INSERT INTO erp_production_orders(id,finished_product_id,order_number,created_at,status) VALUES(?,1,?,"2026-01-01","Aberta")');for($i=10;$i<35;$i++)$insert->execute([$i,'OF'.$i]);$db->exec('PRAGMA query_only=ON');$list=$service->history(1,ArticleProfile::filters(['p'=>'9999']));ap_check($list['total']===27&&$list['page']===2&&count($list['rows'])===7,'Production history paginated; oversized page clamped');
echo "Article profile: all checks passed.\n";
