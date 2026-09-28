<?php
require __DIR__.'/../app/Services/RawMaterialInkLabelService.php';
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,name TEXT); CREATE TABLE erp_ink_types(id INTEGER PRIMARY KEY,name TEXT); CREATE TABLE erp_raw_materials(id INTEGER PRIMARY KEY,code TEXT,description TEXT,product_category TEXT,ink_type_id INTEGER); CREATE TABLE erp_raw_material_ink_labels(id INTEGER PRIMARY KEY AUTOINCREMENT,raw_material_id INTEGER,entry_number TEXT,supplier_lot TEXT,weight_kg REAL,barcode TEXT UNIQUE,label_date TEXT,validated_by INTEGER,validated_at TEXT DEFAULT CURRENT_TIMESTAMP,updated_at TEXT DEFAULT CURRENT_TIMESTAMP); INSERT INTO users VALUES(1,"Operador"); INSERT INTO erp_ink_types VALUES(2,"Base água"); INSERT INTO erp_raw_materials VALUES(7,"TINTA-AZUL","Tinta azul", "subsidiary",2);');
$service=new RawMaterialInkLabelService($pdo);$label=$service->save(7,['entry_number'=>'2026019','supplier_lot'=>'LT-20','weight_kg'=>20.5,'barcode'=>'2601251','label_date'=>'2026-08-20'],1);
if((int)$label['raw_material_id']!==7||$label['article_code']!=='TINTA-AZUL'||$label['ink_type_name']!=='Base água')throw new RuntimeException('A etiqueta não ficou associada à tinta.');
if(abs((float)$label['weight_kg']-20.5)>.001||$label['barcode']!=='2601251')throw new RuntimeException('Os dados da etiqueta de tinta não foram preservados.');
$invalid=false;try{$service->save(7,['entry_number'=>'1'],1);}catch(InvalidArgumentException $e){$invalid=true;}if(!$invalid)throw new RuntimeException('Uma etiqueta incompleta foi aceite.');
echo "raw_material_ink_label_test: OK\n";
