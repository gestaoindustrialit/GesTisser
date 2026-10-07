<?php
require_once __DIR__.'/../app/Services/ProductionOrderStatusService.php';
function status_service_check($condition,$message){if(!$condition)throw new RuntimeException($message);}
$serviceSource=file_get_contents(__DIR__.'/../app/Services/ProductionOrderStatusService.php');
status_service_check(preg_match('/function\s+\w+\s*\([^)]*\?(?:array|int|string|float|bool)\b/',$serviceSource)!==1,'O serviço contém parâmetros nullable incompatíveis com PHP 7.0.');
status_service_check(strpos($serviceSource, '$status=\'Concluída\'')===false,'O Shopfloor ainda pode concluir automaticamente a OF.');
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE erp_production_orders(id INTEGER PRIMARY KEY,status TEXT,updated_at TEXT);CREATE TABLE erp_production_order_operations(id INTEGER PRIMARY KEY,production_order_id INTEGER,status TEXT);CREATE TABLE erp_operation_time_entries(id INTEGER PRIMARY KEY,production_order_operation_id INTEGER,status TEXT,ended_at TEXT);CREATE TABLE erp_production_order_audit(id INTEGER PRIMARY KEY,production_order_id INTEGER,user_id INTEGER,action TEXT,old_value_json TEXT,new_value_json TEXT,reason TEXT);');
$pdo->exec("INSERT INTO erp_production_orders VALUES(1,'Por iniciar',NULL),(2,'Por iniciar',NULL),(3,'Por iniciar',NULL),(4,'Encerrada',NULL);INSERT INTO erp_production_order_operations VALUES(11,1,'Em curso'),(12,2,'Em curso'),(13,3,'Concluída'),(14,4,'Em curso');INSERT INTO erp_operation_time_entries VALUES(21,11,'completed','2026-01-01'),(22,12,'paused',NULL),(23,13,'completed','2026-01-01'),(24,14,'running',NULL);");
status_service_check(ProductionOrderStatusService::syncAll($pdo,7)===3,'Deviam ser atualizadas três OF ativas com tempos.');
$statuses=$pdo->query('SELECT id,status FROM erp_production_orders ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
status_service_check($statuses[1]==='Em Produção','Uma OF com tempos registados deve ficar Em Produção.');
status_service_check($statuses[2]==='Em Pausa','Uma OF com todos os tempos abertos pausados deve ficar Em Pausa.');
status_service_check($statuses[3]==='Em Produção','Concluir a última operação no Shopfloor não pode fechar a OF.');
status_service_check($statuses[4]==='Encerrada','Uma OF encerrada nunca deve ser reaberta automaticamente.');
status_service_check((int)$pdo->query('SELECT COUNT(*) FROM erp_production_order_audit WHERE action="automatic_status"')->fetchColumn()===3,'Cada alteração automática deve ficar auditada.');
echo "production_order_status_service_test: OK\n";
