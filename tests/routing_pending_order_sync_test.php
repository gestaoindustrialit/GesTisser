<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Services/RoutingService.php';

function pending_sync_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE erp_article_routing_versions(id INTEGER PRIMARY KEY,status TEXT)');
$pdo->exec('CREATE TABLE erp_operations(id INTEGER PRIMARY KEY,code TEXT,name TEXT,is_active INTEGER,labour_time_only INTEGER,checklist_template_id INTEGER,checklist_timing TEXT,min_operators INTEGER,default_instructions TEXT)');
$pdo->exec('CREATE TABLE erp_operation_machines(operation_id INTEGER,machine_id INTEGER,is_default INTEGER)');
$pdo->exec('CREATE TABLE erp_work_centers(id INTEGER PRIMARY KEY,is_active INTEGER)');
$pdo->exec('CREATE TABLE erp_article_routing_steps(id INTEGER PRIMARY KEY,routing_version_id INTEGER,operation_id INTEGER,operation_no INTEGER,work_center_id INTEGER,primary_machine_id INTEGER,operators_count INTEGER,setup_time REAL,run_value REAL,calculation_unit TEXT,base_quantity REAL,waste_percent REAL,wait_minutes REAL,transfer_minutes REAL,parallel_allowed INTEGER,specific_instructions TEXT,quality_points TEXT,confirmation_required INTEGER,machine_required INTEGER,operation_snapshot_json TEXT)');
$pdo->exec('CREATE TABLE erp_routing_step_machines(routing_step_id INTEGER,machine_id INTEGER)');
$pdo->exec('CREATE TABLE erp_routing_step_work_centers(routing_step_id INTEGER,work_center_id INTEGER)');
$pdo->exec('CREATE TABLE erp_routing_step_materials(routing_step_id INTEGER,material_id INTEGER,quantity_per_unit REAL,reserve_on_order INTEGER)');
$pdo->exec('CREATE TABLE erp_raw_materials(id INTEGER PRIMARY KEY,status TEXT)');
$pdo->exec('CREATE TABLE erp_operation_time_entries(id INTEGER PRIMARY KEY,production_order_operation_id INTEGER)');
$pdo->exec('CREATE TABLE erp_routing_audit(user_id INTEGER,action TEXT,entity_type TEXT,entity_id INTEGER,before_json TEXT,after_json TEXT,reason TEXT)');
$pdo->exec('CREATE TABLE erp_production_order_operations(id INTEGER PRIMARY KEY,routing_step_id INTEGER,operation_id INTEGER,operation_code TEXT,operation_name TEXT,work_center_id INTEGER,primary_machine_id INTEGER,allowed_machine_ids_json TEXT,allowed_work_center_ids_json TEXT,machine_required INTEGER,operators_count INTEGER,setup_minutes REAL,run_value REAL,calculation_unit TEXT,base_quantity REAL,waste_percent REAL,wait_minutes REAL,transfer_minutes REAL,parallel_allowed INTEGER,instructions TEXT,quality_points TEXT,confirmation_required INTEGER,labour_time_only INTEGER,checklist_template_id INTEGER,checklist_timing TEXT,snapshot_json TEXT,status TEXT)');

$pdo->exec("INSERT INTO erp_article_routing_versions VALUES(1,'active')");
$pdo->exec("INSERT INTO erp_operations VALUES(7,'OP7','Corte',1,1,NULL,NULL,1,'')");
$pdo->exec('INSERT INTO erp_operation_machines VALUES(7,20,1)');
$pdo->exec('INSERT INTO erp_work_centers VALUES(3,1),(4,1)');
$pdo->exec("INSERT INTO erp_article_routing_steps VALUES(10,1,7,10,3,20,1,0,2,'minutes_per_unit',1,0,0,0,0,'Antiga','','1',1,'{}')");
$pdo->exec('INSERT INTO erp_routing_step_machines VALUES(10,20)');
$pdo->exec('INSERT INTO erp_routing_step_work_centers VALUES(10,3)');
$pdo->exec("INSERT INTO erp_production_order_operations(id,routing_step_id,operation_id,operation_code,operation_name,allowed_machine_ids_json,allowed_work_center_ids_json,machine_required,status) VALUES(100,10,7,'OP7','Corte','[20]','[3]',1,'Planeada'),(101,10,7,'OP7','Corte','[20]','[3]',1,'Concluída')");

$service = new RoutingService($pdo);
$service->updateStep(10, [
    'operation_id' => 7,
    'operation_no' => 10,
    'work_center_ids' => [4],
    'machine_required' => 0,
    'operators_count' => 1,
    'run_value' => 3,
    'calculation_unit' => 'minutes_per_unit',
    'base_quantity' => 1,
    'specific_instructions' => 'Nova',
], 9);

$pending = $pdo->query('SELECT * FROM erp_production_order_operations WHERE id=100')->fetch(PDO::FETCH_ASSOC);
$completed = $pdo->query('SELECT * FROM erp_production_order_operations WHERE id=101')->fetch(PDO::FETCH_ASSOC);
pending_sync_check((int) $pending['machine_required'] === 0, 'A OF planeada manteve o requisito antigo de máquina.');
pending_sync_check($pending['allowed_machine_ids_json'] === '[]', 'A OF planeada manteve máquinas antigas.');
pending_sync_check($pending['allowed_work_center_ids_json'] === '[4]', 'A OF planeada não recebeu o novo posto.');
pending_sync_check((int) $pending['labour_time_only'] === 1, 'A operação sem máquina não passou a contabilizar só o colaborador.');
pending_sync_check((int) $completed['machine_required'] === 1, 'Uma operação concluída foi alterada retroativamente.');

echo "Sincronização das operações pendentes da OF validada.\n";
