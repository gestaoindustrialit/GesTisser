<?php
require_once __DIR__.'/../app/Services/InkMaterialSchema.php';
function reconcile_check($ok,$label){if(!$ok)throw new RuntimeException($label);}
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE erp_ink_types(id INTEGER PRIMARY KEY,code TEXT,name TEXT,icon TEXT,is_active INTEGER);INSERT INTO erp_ink_types VALUES(10,"AGUA","Tinta de água","bi-droplet-fill",1),(20,"SOLVENTE","Tinta de solvente","bi-bucket-fill",1),(30,"UV","Tinta de UV","bi-circle-fill",1);CREATE TABLE erp_material_types(id INTEGER PRIMARY KEY,code TEXT);INSERT INTO erp_material_types VALUES(1,"TINTA");CREATE TABLE erp_material_features(id INTEGER PRIMARY KEY,description TEXT);INSERT INTO erp_material_features VALUES(1,"COR: DOURADO | PANTONE: 871"),(2,"COR: AZUL | PANTONE: 072");CREATE TABLE erp_colors(id INTEGER PRIMARY KEY,name TEXT,pantone TEXT);CREATE TABLE erp_raw_materials(id INTEGER PRIMARY KEY,code TEXT,description TEXT,color_id INTEGER,material_type_id INTEGER,material_feature_id INTEGER,ink_type_id INTEGER,is_water_based_ink INTEGER,is_solvent_based_ink INTEGER,is_ink INTEGER,ink_color TEXT,ink_pantone TEXT,legacy_ink_id TEXT,last_price REAL);INSERT INTO erp_raw_materials VALUES(55,"504355","TINTA DOURADO PANTONE 871",NULL,1,1,10,1,0,1,"TINTA DOURADO PANTONE 871",NULL,"215",4.12),(56,"UV-1","Tinta UV",NULL,1,NULL,30,1,1,1,"AMARELO","000",NULL,7.05),(57,"SOL-1","Tinta solvente",NULL,1,NULL,20,0,0,1,"AZUL","072",NULL,6.65),(58,"TINTA-SEM-BASE","Tinta azul",NULL,1,2,NULL,0,0,0,NULL,NULL,NULL,3.5),(59,"CONFLITO","Tinta existente",NULL,1,1,10,1,0,1,"AZUL","871",NULL,2);CREATE TABLE erp_stock_balances(material_id INTEGER,physical_qty REAL);INSERT INTO erp_stock_balances VALUES(55,19.69);CREATE TABLE erp_stock_movements(id INTEGER,material_id INTEGER,quantity REAL);INSERT INTO erp_stock_movements VALUES(7,55,19.69)');
$types=$pdo->query('SELECT * FROM erp_ink_types ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$stock=$pdo->query('SELECT * FROM erp_stock_balances')->fetchAll(PDO::FETCH_ASSOC);$movements=$pdo->query('SELECT * FROM erp_stock_movements')->fetchAll(PDO::FETCH_ASSOC);
InkMaterialSchema::ensure($pdo);
$gold=$pdo->query('SELECT * FROM erp_raw_materials WHERE id=55')->fetch(PDO::FETCH_ASSOC);
reconcile_check($gold['code']==='504355'&&$gold['ink_color']==='DOURADO'&&$gold['ink_pantone']==='871'&&(int)$gold['ink_type_id']===10&&$gold['legacy_ink_id']==='215','Reutiliza o material do exemplo, característica e tipo existentes.');
reconcile_check($types===$pdo->query('SELECT * FROM erp_ink_types ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),'Não altera tipos, IDs ou ícones configurados.');
$uv=$pdo->query('SELECT * FROM erp_raw_materials WHERE id=56')->fetch(PDO::FETCH_ASSOC);
reconcile_check((int)$uv['ink_type_id']===30&&(int)$uv['is_water_based_ink']===0&&(int)$uv['is_solvent_based_ink']===0,'Tipo UV prevalece sobre indicadores antigos contraditórios.');
$solvent=$pdo->query('SELECT * FROM erp_raw_materials WHERE id=57')->fetch(PDO::FETCH_ASSOC);
reconcile_check((int)$solvent['is_solvent_based_ink']===1,'Indicador solvente decorre do tipo configurado.');
$unclassified=$pdo->query('SELECT * FROM erp_raw_materials WHERE id=58')->fetch(PDO::FETCH_ASSOC);
reconcile_check((int)$unclassified['is_ink']===1&&$unclassified['ink_pantone']==='072'&&$unclassified['ink_type_id']===null,'Reconhece tipo de material TINTA sem inventar a base.');
reconcile_check($stock===$pdo->query('SELECT * FROM erp_stock_balances')->fetchAll(PDO::FETCH_ASSOC)&&$movements===$pdo->query('SELECT * FROM erp_stock_movements')->fetchAll(PDO::FETCH_ASSOC),'Stock e histórico permanecem iguais.');
reconcile_check($pdo->query('SELECT ink_color FROM erp_raw_materials WHERE id=59')->fetchColumn()==='AZUL'&&(int)$pdo->query('SELECT COUNT(*) FROM erp_ink_material_reconciliation_log WHERE status="pending"')->fetchColumn()===1,'Divergência explícita fica para revisão; não é sobrescrita.');
$before=$pdo->query('SELECT total_changes()')->fetchColumn();InkMaterialSchema::ensure($pdo);reconcile_check($before===$pdo->query('SELECT total_changes()')->fetchColumn(),'Migração versionada não repete escritas.');
$v=InkMaterialFields::normalize($pdo,['code'=>'UV-1','is_water_based_ink'=>'Não'],$uv);
reconcile_check($v['ink_type_id']===30&&$v['is_solvent_based_ink']===0,'Importar Não em Tinta Água não transforma UV em solvente.');
try{InkMaterialFields::normalize($pdo,['code'=>'UV-1','is_water_based_ink'=>'Sim'],$uv);throw new RuntimeException('Contradição aceite');}catch(RuntimeException $e){reconcile_check(strpos($e->getMessage(),'contradiz')!==false,'Importação contraditória rejeitada.');}
$input=['code'=>'NOVO','ink_type_id'=>30,'ink_color'=>'AZUL','ink_pantone'=>'000'];$new=InkMaterialFields::normalize($pdo,$input);
reconcile_check($new['ink_type_id']===30&&$new['is_water_based_ink']===0,'Novas tintas usam o catálogo existente.');
try{InkMaterialFields::normalize($pdo,['code'=>'504355','ink_color'=>'AZUL'],$gold);throw new RuntimeException('Característica ignorada');}catch(RuntimeException $e){reconcile_check(strpos($e->getMessage(),'característica')!==false,'Cor incompatível com característica é rejeitada.');}
echo "ink_material_reconciliation_test: OK\n";
