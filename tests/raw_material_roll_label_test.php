<?php
require __DIR__.'/../app/Services/RawMaterialRollLabelService.php';
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,name TEXT); CREATE TABLE erp_raw_materials(id INTEGER PRIMARY KEY,code TEXT,description TEXT,roll_controlled INTEGER); CREATE TABLE erp_raw_material_roll_labels(id INTEGER PRIMARY KEY AUTOINCREMENT,raw_material_id INTEGER,entry_number TEXT,supplier_lot TEXT,metres REAL,weight_kg REAL,barcode TEXT UNIQUE,label_date TEXT,validated_by INTEGER,validated_at TEXT DEFAULT CURRENT_TIMESTAMP,updated_at TEXT DEFAULT CURRENT_TIMESTAMP); INSERT INTO users VALUES(1,"Operador"); INSERT INTO erp_raw_materials VALUES(7,"RFBRLA476020","Ráfia branca laminada 47cm, 60+20gr/m2",1);');
$service=new RawMaterialRollLabelService($pdo);
$label=$service->save(7,['entry_number'=>'2026019','supplier_lot'=>'DA MAN0201','metres'=>3579,'weight_kg'=>269.20,'barcode'=>'2601250','label_date'=>'2026-08-20'],1);
if((int)$label['raw_material_id']!==7||$label['article_code']!=='RFBRLA476020'||$label['entry_number']!=='2026019')throw new RuntimeException('A etiqueta não ficou associada à matéria-prima.');
if(abs((float)$label['weight_kg']-269.20)>.001||$label['barcode']!=='2601250')throw new RuntimeException('Os dados da etiqueta anterior não foram preservados.');
$invalid=false;try{$service->save(7,['entry_number'=>'1'],1);}catch(InvalidArgumentException $e){$invalid=true;}if(!$invalid)throw new RuntimeException('Uma etiqueta incompleta foi aceite.');
echo "raw_material_roll_label_test: OK\n";
