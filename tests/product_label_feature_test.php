<?php
require_once __DIR__.'/../app/Services/ProductLabelService.php';
require_once __DIR__.'/../app/Services/Code128Barcode.php';
function product_feature_assert($condition,$message){if(!$condition)throw new RuntimeException($message);}
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE erp_production_orders(id INTEGER PRIMARY KEY,order_number TEXT,planned_quantity REAL,delivery_address_snapshot TEXT,customer_id INTEGER,delivery_address_id INTEGER,finished_product_id INTEGER,product_id INTEGER)');
$pdo->exec('CREATE TABLE erp_production_order_snapshots(id INTEGER PRIMARY KEY,production_order_id INTEGER,snapshot_json TEXT)');
$pdo->exec('CREATE TABLE erp_technical_sheets(id INTEGER PRIMARY KEY,production_order_id INTEGER,snapshot_json TEXT)');
$pdo->exec('CREATE TABLE erp_production_order_operations(id INTEGER PRIMARY KEY,production_order_id INTEGER,operation_id INTEGER)');
$pdo->exec('CREATE TABLE erp_operations(id INTEGER PRIMARY KEY,product_label_enabled INTEGER)');
$pdo->exec('CREATE TABLE erp_customers(id INTEGER PRIMARY KEY,name TEXT,postal_code TEXT,city TEXT)');
$pdo->exec('CREATE TABLE erp_customer_delivery_addresses(id INTEGER PRIMARY KEY,postal_code TEXT,city TEXT)');
$pdo->exec('CREATE TABLE erp_products(id INTEGER PRIMARY KEY,code TEXT,description TEXT)');
$pdo->exec('CREATE TABLE erp_finished_products(id INTEGER PRIMARY KEY,code TEXT,description TEXT)');
$pdo->exec('CREATE TABLE erp_operation_time_entries(id INTEGER PRIMARY KEY,production_order_operation_id INTEGER,quantity_good REAL)');
$pdo->exec('CREATE TABLE erp_production_order_audit(id INTEGER PRIMARY KEY,production_order_id INTEGER,user_id INTEGER,action TEXT,new_value_json TEXT)');
$pdo->exec("INSERT INTO erp_customers VALUES(2,'Cliente','2305-127','Tomar');INSERT INTO erp_finished_products VALUES(3,'ART-01','Produto acabado');INSERT INTO erp_operations VALUES(4,1);INSERT INTO erp_production_orders VALUES(5,'OF-5',20000,'{}',2,NULL,3,NULL);INSERT INTO erp_production_order_snapshots VALUES(8,5,'{\"_order\":{\"lot\":\"T20260446\"}}');INSERT INTO erp_production_order_operations VALUES(6,5,4);INSERT INTO erp_operation_time_entries VALUES(7,6,5000)");
$service=new ProductLabelService($pdo);$data=$service->data(5,6);
product_feature_assert($data['customer_name']==='Cliente'&&$data['locality']==='2305-127 Tomar','dados de cliente/localidade incorretos');
product_feature_assert($data['product_code']==='ART-01'&&$data['lot']==='T20260446','dados de produto/lote incorretos');
product_feature_assert((float)$data['default_quantity']===5000.0,'quantidade produzida não usada como sugestão');
$values=$service->validatePrint(['quantity'=>'5000','copies'=>'2','label_date'=>'2026-10-01']);product_feature_assert($values['copies']===2,'cópias inválidas');
foreach([['quantity'=>0,'copies'=>1,'label_date'=>'2026-10-01'],['quantity'=>1,'copies'=>100,'label_date'=>'2026-10-01']] as$invalid){try{$service->validatePrint($invalid);throw new RuntimeException('validação permissiva');}catch(InvalidArgumentException $expected){}}
$service->audit($data,$values,9);product_feature_assert($pdo->query("SELECT action FROM erp_production_order_audit")->fetchColumn()==='production_label_printed','auditoria em falta');
$svg=Code128Barcode::svg('T20260446');product_feature_assert(strpos($svg,'<svg')!==false&&strpos($svg,'T20260446')!==false,'CODE 128 em falta');
$pdo->exec('UPDATE erp_operations SET product_label_enabled=0');try{$service->data(5,6);throw new RuntimeException('operação sem capacidade autorizada');}catch(RuntimeException $expected){}
$operationUi=file_get_contents(__DIR__.'/../erp_operations.php');$shopfloor=file_get_contents(__DIR__.'/../shopfloor.php');$endpoint=file_get_contents(__DIR__.'/../product_label.php');
product_feature_assert(strpos($operationUi,'product_label_enabled')!==false,'configuração no admin em falta');
product_feature_assert(strpos($shopfloor,"!empty(\$op['product_label_enabled'])")!==false,'visibilidade do botão não depende da flag');
product_feature_assert(strpos($shopfloor,'o.lot')===false,'o Shopfloor consulta uma coluna de lote inexistente na OF');
product_feature_assert(strpos($shopfloor,"['_order']['lot']")!==false,'o Shopfloor não carrega o lote do snapshot da OF');
product_feature_assert(strpos($endpoint,'ProductLabelService')!==false&&strpos($endpoint,'production_order_id')!==false&&strpos($endpoint,'operation_id')!==false,'endpoint seguro em falta');
product_feature_assert(strpos($endpoint,'@page{size:A5 landscape')!==false&&strpos($endpoint,'@media print')!==false,'CSS de impressão em falta');
echo "product_label_feature_test: OK\n";
