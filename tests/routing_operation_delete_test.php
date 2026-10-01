<?php
declare(strict_types=1);

require_once __DIR__.'/../app/Services/RoutingService.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "OK: {$message}\n";
}

$pdo=new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys=ON');
$pdo->exec('CREATE TABLE erp_operations(id INTEGER PRIMARY KEY,code TEXT,name TEXT);CREATE TABLE erp_operation_machines(operation_id INTEGER REFERENCES erp_operations(id) ON DELETE CASCADE,machine_id INTEGER);CREATE TABLE erp_operation_documents(id INTEGER PRIMARY KEY,operation_id INTEGER REFERENCES erp_operations(id) ON DELETE CASCADE,file_url TEXT);CREATE TABLE erp_article_routing_steps(id INTEGER PRIMARY KEY,operation_id INTEGER REFERENCES erp_operations(id) ON DELETE RESTRICT);CREATE TABLE erp_production_order_operations(id INTEGER PRIMARY KEY,operation_id INTEGER REFERENCES erp_operations(id) ON DELETE RESTRICT);CREATE TABLE erp_routing_audit(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,action TEXT,entity_type TEXT,entity_id INTEGER,before_json TEXT,after_json TEXT,reason TEXT)');
$pdo->exec("INSERT INTO erp_operations VALUES(1,'OP1','Livre'),(2,'OP2','Em routing'),(3,'OP3','Em OF');INSERT INTO erp_operation_machines VALUES(1,10);INSERT INTO erp_operation_documents VALUES(1,1,'storage/uploads/instruction.pdf');INSERT INTO erp_article_routing_steps VALUES(1,2);INSERT INTO erp_production_order_operations VALUES(1,3)");
$service=new RoutingService($pdo);
$files=$service->deleteOperation(1,7);
check($files===['storage/uploads/instruction.pdf'],'devolve os anexos da operação eliminada');
check((int)$pdo->query('SELECT COUNT(*) FROM erp_operations WHERE id=1')->fetchColumn()===0,'elimina uma operação sem utilizações');
check((int)$pdo->query('SELECT COUNT(*) FROM erp_operation_documents WHERE operation_id=1')->fetchColumn()===0,'elimina relações dependentes por cascata');
check((int)$pdo->query("SELECT COUNT(*) FROM erp_routing_audit WHERE action='delete' AND entity_type='operation' AND entity_id=1")->fetchColumn()===1,'regista a eliminação na auditoria');
foreach([2,3] as$id){
    try{$service->deleteOperation($id,7);check(false,'bloqueia a eliminação de uma operação utilizada');}
    catch(DomainException $exception){check(strpos($exception->getMessage(),'Desative-a')!==false,'explica como tratar uma operação utilizada');}
}
