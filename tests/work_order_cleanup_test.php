<?php
declare(strict_types=1);

require_once __DIR__ . '/../erp_work_order_cleanup.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys=ON');
$pdo->exec('CREATE TABLE erp_production_orders(id INTEGER PRIMARY KEY); CREATE TABLE erp_production_order_audit(id INTEGER PRIMARY KEY,production_order_id INTEGER REFERENCES erp_production_orders(id) ON DELETE CASCADE); CREATE TABLE erp_production_order_closures(id INTEGER PRIMARY KEY,production_order_id INTEGER REFERENCES erp_production_orders(id) ON DELETE RESTRICT); CREATE TABLE erp_raw_material_roll_consumptions(id INTEGER PRIMARY KEY,production_order_id INTEGER REFERENCES erp_production_orders(id) ON DELETE RESTRICT); CREATE TABLE erp_audit_log(id INTEGER PRIMARY KEY,entity TEXT);');
$pdo->exec("INSERT INTO erp_production_orders VALUES(1),(2); INSERT INTO erp_production_order_audit VALUES(1,1); INSERT INTO erp_production_order_closures VALUES(1,1); INSERT INTO erp_raw_material_roll_consumptions VALUES(1,2); INSERT INTO erp_audit_log VALUES(1,'erp_production_orders'),(2,'erp_customers');");

if (gt_erp_delete_all_work_orders($pdo) !== 2) throw new RuntimeException('The deleted order count is incorrect.');
foreach (['erp_production_orders','erp_production_order_audit','erp_production_order_closures','erp_raw_material_roll_consumptions'] as $table) {
    if ((int) $pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn() !== 0) throw new RuntimeException($table.' was not cleared.');
}
if ((int) $pdo->query('SELECT COUNT(*) FROM erp_audit_log')->fetchColumn() !== 1) throw new RuntimeException('Unrelated audit entries were removed.');

echo "work order cleanup: ok\n";
