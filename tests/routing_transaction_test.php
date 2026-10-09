<?php
require_once dirname(__DIR__).'/app/Services/RoutingService.php';
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->exec('CREATE TABLE items(id INTEGER PRIMARY KEY)');
$service=new RoutingService($pdo);$method=new ReflectionMethod(RoutingService::class,'transaction');$method->setAccessible(true);
$method->invoke($service,function()use($pdo,$service,$method){$pdo->exec('INSERT INTO items VALUES(1)');$method->invoke($service,function()use($pdo){$pdo->exec('INSERT INTO items VALUES(2)');});});
if((int)$pdo->query('SELECT COUNT(*) FROM items')->fetchColumn()!==2)throw new RuntimeException('Nested routing transaction was not committed.');
try{$method->invoke($service,function()use($pdo,$service,$method){$pdo->exec('INSERT INTO items VALUES(3)');$method->invoke($service,function()use($pdo){$pdo->exec('INSERT INTO items VALUES(4)');throw new RuntimeException('rollback fixture');});});throw new LogicException('Failure did not propagate.');}catch(RuntimeException $e){if($e->getMessage()!=='rollback fixture')throw $e;}
if((int)$pdo->query('SELECT COUNT(*) FROM items')->fetchColumn()!==2)throw new RuntimeException('Nested routing failure left partial writes.');
$pdo->beginTransaction();$method->invoke($service,function()use($pdo){$pdo->exec('INSERT INTO items VALUES(5)');});if(!$pdo->inTransaction())throw new RuntimeException('Routing committed the caller transaction.');$pdo->rollBack();
if((int)$pdo->query('SELECT COUNT(*) FROM items')->fetchColumn()!==2)throw new RuntimeException('Caller rollback failed.');
$method->invoke($service,function()use($pdo){$pdo->exec('INSERT INTO items VALUES(6)');});if((int)$pdo->query('SELECT COUNT(*) FROM items')->fetchColumn()!==3)throw new RuntimeException('Service did not recover after rollback.');
echo "Routing transactions: nested commit, atomic rollback, caller ownership and recovery passed.\n";
