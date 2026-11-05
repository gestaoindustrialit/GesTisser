<?php
require_once __DIR__.'/../erp_migrations.php';
require_once __DIR__.'/../app/Services/PurchaseOrderLineSchema.php';
function legacy_check($ok,$message){if(!$ok)throw new RuntimeException($message);}
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys=ON;CREATE TABLE erp_purchase_order_lines(id INTEGER PRIMARY KEY,purchase_order_id INTEGER NOT NULL,line_no INTEGER NOT NULL,item_type TEXT NOT NULL,item_id INTEGER NOT NULL,ordered_quantity REAL NOT NULL,unit_cost REAL NOT NULL);CREATE TABLE receipts(id INTEGER PRIMARY KEY,line_id INTEGER REFERENCES erp_purchase_order_lines(id));INSERT INTO erp_purchase_order_lines VALUES(73,2,1,"raw_material",9,40,6.65);INSERT INTO receipts VALUES(1,73)');
gt_erp_migrate_purchase_order_lines($pdo);
$row=$pdo->query('SELECT * FROM erp_purchase_order_lines WHERE id=73')->fetch(PDO::FETCH_ASSOC);
legacy_check((int)$row['line_number']===1&&(float)$row['quantity']===40.0&&(float)$row['unit_price']===6.65,'Valores antigos migrados.');
legacy_check((int)$pdo->query('SELECT line_id FROM receipts')->fetchColumn()===73,'Referência da receção preservada.');
$insert=PurchaseOrderLineSchema::prepareInsert($pdo,['purchase_order_id','line_number','item_type','item_id','quantity','unit_price']);
$insert->execute(['purchase_order_id'=>2,'line_number'=>2,'item_type'=>'raw_material','item_id'=>10,'quantity'=>20,'unit_price'=>6.49]);
$row=$pdo->query('SELECT * FROM erp_purchase_order_lines WHERE line_number=2')->fetch(PDO::FETCH_ASSOC);
legacy_check((int)$row['line_no']===2&&(float)$row['ordered_quantity']===20.0&&(float)$row['unit_cost']===6.49,'Inserção preenche campos antigos obrigatórios e campos novos.');
gt_erp_migrate_purchase_order_lines($pdo);
legacy_check((int)$pdo->query('SELECT COUNT(*) FROM erp_purchase_order_lines')->fetchColumn()===2,'Migração idempotente.');
legacy_check(!$pdo->query('PRAGMA foreign_key_check')->fetch(),'Integridade referencial.');
require_once __DIR__.'/../app/Services/PurchaseOrderImportService.php';
$pdo->exec('CREATE TABLE erp_purchase_orders(id INTEGER PRIMARY KEY,order_number TEXT,supplier_id INTEGER,order_date TEXT,expected_date TEXT,supplier_reference TEXT,status TEXT,created_by INTEGER);CREATE TABLE erp_number_sequences(code TEXT,prefix TEXT,next_number INTEGER,padding INTEGER,suffix TEXT);INSERT INTO erp_number_sequences VALUES("purchase_order","ENC-",1,5,"");CREATE TABLE erp_order_imports(id INTEGER PRIMARY KEY,purchase_order_id INTEGER,validated_data_json TEXT,status TEXT,validated_by INTEGER,validated_at TEXT);INSERT INTO erp_order_imports(id,status) VALUES(1,"prepared")');
$orderId=(new PurchaseOrderImportService($pdo))->create(['supplier'=>['id'=>1],'order_date'=>'2026-11-05','lines'=>[['type'=>'ARTICLE','matched_item_id'=>9,'matched_item_type'=>'raw_material','line_number'=>1,'description'=>'Importado','quantity'=>4,'unit_original'=>'KG','unit_normalized'=>'KG','unit_price'=>3,'total'=>12]]],1,7);
$row=$pdo->query('SELECT * FROM erp_purchase_order_lines WHERE purchase_order_id='.$orderId)->fetch(PDO::FETCH_ASSOC);
legacy_check((int)$row['line_no']===1&&(float)$row['ordered_quantity']===4.0&&(float)$row['unit_cost']===3.0,'Importação PDF também suporta a tabela legacy.');
echo "purchase_order_legacy_schema_test: OK\n";
