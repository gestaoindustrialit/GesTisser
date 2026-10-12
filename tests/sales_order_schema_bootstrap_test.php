<?php
require_once dirname(__DIR__).'/app/Services/SalesOrderSchema.php';
function schema_check($condition,$message){if(!$condition)throw new RuntimeException($message);echo 'PASS: '.$message.PHP_EOL;}
function schema_db(){ $pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->exec('CREATE TABLE erp_number_sequences(id INTEGER PRIMARY KEY,code TEXT UNIQUE,prefix TEXT,next_number INTEGER,padding INTEGER)');return $pdo; }
$db=schema_db();$db->exec('PRAGMA query_only=ON');
schema_check(!SalesOrderSchema::ensure($db,'production','/application','/application/database.sqlite'),'Production database is not migrated');
schema_check(!$db->query("SELECT 1 FROM sqlite_master WHERE name='erp_sales_orders'")->fetchColumn(),'Production guard leaves tables absent');
schema_check(SalesOrderSchema::ensure($db,'gestisser-dev','/application','/application/database.sqlite'),'Development automatically creates missing schema');
schema_check((int)$db->query('PRAGMA query_only')->fetchColumn()===1,'Read-only mode restored after migration');
$changes=$db->query('SELECT total_changes()')->fetchColumn();
schema_check(SalesOrderSchema::ensure($db,'gestisser-dev','/application','/application/database.sqlite')&&$changes==$db->query('SELECT total_changes()')->fetchColumn(),'Complete schema skips all migration writes');
$db->exec('PRAGMA query_only=OFF');$db->exec("UPDATE erp_number_sequences SET next_number=8 WHERE code='sales_order';DROP INDEX idx_sales_of_line");$db->exec('PRAGMA query_only=ON');
schema_check(!SalesOrderSchema::ready($db),'Incomplete schema detected even with delivery table present');
schema_check(SalesOrderSchema::ensure($db,'development','/application','/application/database.sqlite'),'Missing index repaired automatically');
schema_check((int)$db->query("SELECT next_number FROM erp_number_sequences WHERE code='sales_order'")->fetchColumn()===8,'Repair preserves current numbering');
$db->exec('PRAGMA query_only=OFF');$db->exec("DELETE FROM erp_number_sequences WHERE code='sales_order'");$db->exec('PRAGMA query_only=ON');
schema_check(!SalesOrderSchema::ready($db)&&SalesOrderSchema::ensure($db,'test','/application','/application/database.sqlite'),'Missing commercial sequence detected and prepared');
$legacy=schema_db();$legacy->exec('CREATE TABLE erp_sales_orders(id INTEGER PRIMARY KEY,order_number TEXT,customer_id INTEGER,order_date TEXT,expected_date TEXT,status TEXT)');$legacy->exec('INSERT INTO erp_sales_orders VALUES(7,"EC-00007",1,"2026-10-12",NULL,"Confirmada")');
SalesOrderSchema::migrate($legacy);
schema_check(in_array('requisition_number',array_column($legacy->query('PRAGMA table_info(erp_sales_orders)')->fetchAll(PDO::FETCH_ASSOC),'name'),true),'Existing order schema gains requisition column');
schema_check($legacy->query('SELECT order_number FROM erp_sales_orders WHERE id=7')->fetchColumn()==='EC-00007','Incremental requisition migration preserves saved orders');
$broken=new PDO('sqlite::memory:');$broken->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$broken->exec('CREATE TABLE erp_number_sequences(code TEXT)');$broken->exec('PRAGMA query_only=ON');
try {SalesOrderSchema::ensure($broken,'gestisser-dev','/application','/application/database.sqlite');throw new LogicException('Expected failure');}catch(PDOException $e){}
schema_check((int)$broken->query('PRAGMA query_only')->fetchColumn()===1&&!$broken->inTransaction(),'Failure restores read-only mode and rolls back transaction');
schema_check(!$broken->query("SELECT 1 FROM sqlite_master WHERE name='erp_sales_orders'")->fetchColumn(),'Failed migration leaves no partial schema');
$base=sys_get_temp_dir().'/sales-schema-'.uniqid();mkdir($base);mkdir($base.'/gestisser-dev');touch($base.'/gestisser-dev/database.sqlite');touch($base.'/production.sqlite');
try {
    schema_check(SalesOrderSchema::developmentAllowed('production',$base.'/gestisser-dev',$base.'/gestisser-dev/database.sqlite'),'Trusted dev directory with its own DB handles inherited environment setting');
    schema_check(!SalesOrderSchema::developmentAllowed('production',$base.'/gestisser-dev',$base.'/production.sqlite'),'Dev directory cannot authorize an external production DB');
}finally{unlink($base.'/gestisser-dev/database.sqlite');unlink($base.'/production.sqlite');rmdir($base.'/gestisser-dev');rmdir($base);}
