<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/Services/OperationChecklistService.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE checklist_template_items (id INTEGER PRIMARY KEY, template_id INTEGER, content TEXT, position INTEGER, field_type TEXT, options_json TEXT, is_required INTEGER)');
$pdo->exec('CREATE TABLE erp_production_order_operations (id INTEGER PRIMARY KEY, production_order_id INTEGER, operation_id INTEGER)');
$pdo->exec('CREATE TABLE erp_operation_checklist_responses (id INTEGER PRIMARY KEY, production_order_operation_id INTEGER, time_entry_id INTEGER, checklist_template_id INTEGER, user_id INTEGER, phase TEXT, response_json TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec("INSERT INTO checklist_template_items VALUES (1,10,'Confirmar proteção',1,'checkbox',NULL,1),(2,10,'Temperatura',2,'number',NULL,1),(3,10,'Resultado',3,'select','[\"Conforme\",\"Rejeitado\"]',1)");
$pdo->exec('INSERT INTO erp_production_order_operations VALUES (20,100,7)');
$service = new OperationChecklistService($pdo);
$operation = ['id'=>20,'production_order_id'=>100,'operation_id'=>7,'checklist_template_id'=>10,'checklist_timing'=>'first'];
assert($service->isRequired($operation, 5, 'start') === true);
try { $service->validateAndEncode(10, [1=>1,2=>'abc',3=>'Conforme']); assert(false); } catch (InvalidArgumentException $e) { assert(strpos($e->getMessage(), 'número') !== false); }
$service->save($operation, 5, 'start', [1=>1,2=>'21.5',3=>'Conforme'], null);
assert($service->isRequired($operation, 5, 'start') === false);
assert($service->isRequired($operation, 6, 'start') === true);
$stored = json_decode((string) $pdo->query('SELECT response_json FROM erp_operation_checklist_responses')->fetchColumn(), true);
assert($stored[1]['value'] === '21.5' && $stored[2]['value'] === 'Conforme');
echo "Operation checklist service: OK\n";
