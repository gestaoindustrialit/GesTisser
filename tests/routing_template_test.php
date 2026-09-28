<?php
require_once __DIR__.'/../app/Services/RoutingService.php';
$pdo=new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$schema=[
'CREATE TABLE erp_article_routings(id INTEGER PRIMARY KEY AUTOINCREMENT,finished_product_id INTEGER,created_by INTEGER)',
'CREATE TABLE erp_article_routing_versions(id INTEGER PRIMARY KEY AUTOINCREMENT,routing_id INTEGER,version_no INTEGER,status TEXT DEFAULT "draft",based_on_version_id INTEGER,created_by INTEGER)',
'CREATE TABLE erp_article_routing_steps(id INTEGER PRIMARY KEY AUTOINCREMENT,routing_version_id INTEGER,operation_id INTEGER,operation_no INTEGER,sort_order INTEGER,work_center_id INTEGER,primary_machine_id INTEGER,operators_count INTEGER,setup_time REAL,run_value REAL,calculation_unit TEXT,base_quantity REAL,waste_percent REAL,wait_minutes REAL,transfer_minutes REAL,parallel_allowed INTEGER,specific_instructions TEXT,quality_points TEXT,confirmation_required INTEGER,is_active INTEGER,operation_snapshot_json TEXT)',
'CREATE TABLE erp_routing_step_machines(routing_step_id INTEGER,machine_id INTEGER)',
'CREATE TABLE erp_routing_step_materials(id INTEGER PRIMARY KEY AUTOINCREMENT,routing_step_id INTEGER,material_id INTEGER,quantity_per_unit REAL,reserve_on_order INTEGER)',
'CREATE TABLE erp_routing_templates(id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT UNIQUE,description TEXT,snapshot_json TEXT,source_routing_version_id INTEGER,is_active INTEGER DEFAULT 1,created_by INTEGER,updated_by INTEGER,created_at TEXT DEFAULT CURRENT_TIMESTAMP,updated_at TEXT DEFAULT CURRENT_TIMESTAMP)',
'CREATE TABLE erp_operations(id INTEGER PRIMARY KEY,is_active INTEGER)',
'CREATE TABLE erp_machines(id INTEGER PRIMARY KEY,is_active INTEGER,deleted_at TEXT)',
'CREATE TABLE erp_raw_materials(id INTEGER PRIMARY KEY,status TEXT)',
'CREATE TABLE erp_routing_audit(user_id INTEGER,action TEXT,entity_type TEXT,entity_id INTEGER,before_json TEXT,after_json TEXT,reason TEXT)'];
foreach($schema as$sql)$pdo->exec($sql);
$pdo->exec('INSERT INTO erp_operations VALUES(10,1);INSERT INTO erp_machines VALUES(20,1,NULL);INSERT INTO erp_raw_materials VALUES(30,"Ativo");INSERT INTO erp_article_routings(finished_product_id,created_by) VALUES(1,7);INSERT INTO erp_article_routing_versions(routing_id,version_no,created_by) VALUES(1,1,7)');
$pdo->exec("INSERT INTO erp_article_routing_steps(routing_version_id,operation_id,operation_no,sort_order,primary_machine_id,operators_count,setup_time,run_value,calculation_unit,base_quantity,waste_percent,wait_minutes,transfer_minutes,parallel_allowed,specific_instructions,quality_points,confirmation_required,is_active,operation_snapshot_json) VALUES(1,10,10,10,20,2,5,12,'seconds_per_unit',1,0,3,1,0,'Preparar','Verificar',1,1,'{}')");
$pdo->exec('INSERT INTO erp_routing_step_machines VALUES(1,20);INSERT INTO erp_routing_step_materials(routing_step_id,material_id,quantity_per_unit,reserve_on_order) VALUES(1,30,0.25,1)');
$service=new RoutingService($pdo);
$template=$service->saveTemplateFromVersion(1,'Saco standard','Com ráfia',7);
$version=$service->createVersionFromTemplate(2,$template,7);
$step=$pdo->query('SELECT * FROM erp_article_routing_steps WHERE routing_version_id='.$version)->fetch(PDO::FETCH_ASSOC);
if(!$step||(int)$step['operation_id']!==10||(int)$step['sort_order']!==10||(int)$step['primary_machine_id']!==20||(float)$step['setup_time']!==5.0||(float)$step['run_value']!==12.0)throw new RuntimeException('O template não preservou operação, ordem, máquina e tempos.');
$material=$pdo->query('SELECT * FROM erp_routing_step_materials WHERE routing_step_id='.(int)$step['id'])->fetch(PDO::FETCH_ASSOC);
if(!$material||(int)$material['material_id']!==30||(float)$material['quantity_per_unit']!==0.25||(int)$material['reserve_on_order']!==1)throw new RuntimeException('O template não preservou os consumos.');
$pdo->exec('UPDATE erp_routing_step_materials SET material_id=31 WHERE routing_step_id='.(int)$step['id']);
if((int)$pdo->query('SELECT material_id FROM erp_routing_step_materials WHERE routing_step_id=1')->fetchColumn()!==30)throw new RuntimeException('Alterar a nova versão modificou a versão de origem.');
echo "routing_template_test: OK\n";
