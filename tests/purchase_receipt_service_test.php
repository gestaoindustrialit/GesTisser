<?php
declare(strict_types=1);
require_once __DIR__.'/../app/Services/PurchaseReceiptService.php';

function receipt_assert($condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }

$pdo=new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE erp_purchase_orders(id INTEGER PRIMARY KEY,order_number TEXT,status TEXT,updated_at TEXT);CREATE TABLE erp_purchase_order_lines(id INTEGER PRIMARY KEY,purchase_order_id INTEGER,line_no INTEGER,item_type TEXT,item_id INTEGER,ordered_quantity REAL,unit_cost REAL);CREATE TABLE erp_stock_movements(id INTEGER PRIMARY KEY AUTOINCREMENT,movement_number TEXT,movement_type TEXT,item_type TEXT,item_id INTEGER,quantity REAL,warehouse_to_id INTEGER,location_to_id INTEGER,unit_cost REAL,total_cost REAL,source_type TEXT,source_id INTEGER,order_reference TEXT,purchase_order_line_id INTEGER,labels_to_print INTEGER,reason TEXT,notes TEXT,created_by INTEGER);CREATE TABLE erp_stock_balances(item_type TEXT,item_id INTEGER,warehouse_id INTEGER,location_id INTEGER,lot TEXT,physical_qty REAL,updated_at TEXT);');
$pdo->exec('INSERT INTO erp_purchase_orders VALUES(1,"ENC-1","Aberta",NULL);INSERT INTO erp_purchase_order_lines VALUES(10,1,1,"raw_material",5,10,2.5);');

$first=PurchaseReceiptService::receive($pdo,['purchase_order_id'=>1,'warehouse_id'=>2,'location_id'=>3,'receipt_line_id'=>[10],'receipt_quantity'=>[4],'receipt_labels'=>[2],'receipt_unit_cost'=>[2.75]],7);
receipt_assert($first['status']==='Parcial','Uma receção parcial deve deixar a encomenda parcial.');
$movement=$pdo->query('SELECT * FROM erp_stock_movements')->fetch(PDO::FETCH_ASSOC);
receipt_assert((float)$movement['quantity']===4.0&&(int)$movement['labels_to_print']===2,'A quantidade e as etiquetas devem ficar associadas à linha recebida.');
receipt_assert((float)$pdo->query('SELECT physical_qty FROM erp_stock_balances')->fetchColumn()===4.0,'A receção deve incrementar o stock.');

$second=PurchaseReceiptService::receive($pdo,['purchase_order_id'=>1,'warehouse_id'=>2,'location_id'=>3,'receipt_line_id'=>[10],'receipt_quantity'=>[6],'receipt_labels'=>[1]],7);
receipt_assert($second['status']==='Recebida','A receção da quantidade em falta deve concluir a encomenda.');
receipt_assert((float)$pdo->query('SELECT physical_qty FROM erp_stock_balances')->fetchColumn()===10.0,'As receções devem acumular no stock.');

$pdo->exec('UPDATE erp_purchase_orders SET status="Parcial" WHERE id=1');
try { PurchaseReceiptService::receive($pdo,['purchase_order_id'=>1,'receipt_line_id'=>[10],'receipt_quantity'=>[0.001]],7); throw new RuntimeException('Era esperado rejeitar excesso.'); } catch (RuntimeException $e) { receipt_assert(strpos($e->getMessage(),'excede')!==false,'O excesso deve produzir uma mensagem explícita.'); }
echo "Receções parciais, quantidades em falta, stock e etiquetas validados.\n";
