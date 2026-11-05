<?php
require_once __DIR__.'/../app/Services/InkMaterialFields.php';
require_once __DIR__.'/../app/Services/RawMaterialSpreadsheet.php';
function ink_check($ok,$message){if(!$ok)throw new RuntimeException($message);}
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE erp_raw_materials(id INTEGER PRIMARY KEY,code TEXT,legacy_ink_id TEXT);CREATE TABLE erp_ink_types(id INTEGER PRIMARY KEY,code TEXT);INSERT INTO erp_ink_types VALUES(1,"AGUA"),(2,"SOLVENTE")');
$v=InkMaterialFields::normalize($pdo,['code'=>'PTHAQ10A296-0566','is_ink'=>1,'ink_color'=>'AMARELO','ink_pantone'=>'000','is_water_based_ink'=>'Sim','last_price'=>'4,12','legacy_ink_id'=>'00215']);
ink_check($v['ink_pantone']==='000'&&$v['legacy_ink_id']==='00215','Zeros de Pantone e ID antigo preservados.');
ink_check($v['last_price']===4.12&&$v['ink_type_id']===1&&$v['is_water_based_ink']===1,'Preço decimal e base de água.');
$old=array_merge($v,['code'=>'PTHAQ10A296-0566','id'=>7]);
$preserved=InkMaterialFields::normalize($pdo,['code'=>$old['code']],$old);
ink_check($preserved['legacy_ink_id']==='00215'&&$preserved['ink_pantone']==='000','Atualização parcial preserva os campos.');
$pdo->exec('INSERT INTO erp_raw_materials VALUES(7,"PTHAQ10A296-0566","00215")');
try{InkMaterialFields::normalize($pdo,['code'=>'OUTRO','ink_color'=>'AZUL','ink_pantone'=>'072','legacy_ink_id'=>'00215']);throw new RuntimeException('ID duplicado aceite');}catch(RuntimeException $e){ink_check(strpos($e->getMessage(),'já está associado')!==false,'ID antigo não pode ser atribuído a outro código.');}
$csv=tempnam(sys_get_temp_dir(),'ink-legacy-');
file_put_contents($csv,"Código (Sint.);Cor;Pantone;Tinta Água;Últ. Preço;ID\nPTHAQ10A296-0566;AMARELO;000;Sim;4,12;00215\n5SF08AZ1183;AZUL;072;Não;7,05;00009\n");
try{$rows=RawMaterialSpreadsheet::read($csv,'csv');}finally{unlink($csv);}
ink_check($rows[0]['codigo']==='PTHAQ10A296-0566'&&$rows[0]['descricao']==='AMARELO','Exportação antiga aceite sem coluna Descrição.');
ink_check($rows[0]['pantone']==='000'&&$rows[1]['pantone']==='072'&&$rows[1]['id_antigo']==='00009','Importação não converte referências em números.');
ink_check($rows[1]['ultimo_preco']==='7,05'&&$rows[1]['tinta_agua']==='Não','Preço e base originais disponíveis para auditoria.');
$xlsx=tempnam(sys_get_temp_dir(),'ink-zeros-');$zip=new ZipArchive();$zip->open($xlsx,ZipArchive::CREATE|ZipArchive::OVERWRITE);
$zip->addFromString('xl/styles.xml','<styleSheet><numFmts><numFmt numFmtId="164" formatCode="000"/></numFmts><cellXfs><xf numFmtId="0"/><xf numFmtId="164"/></cellXfs></styleSheet>');
$zip->addFromString('xl/worksheets/sheet1.xml','<worksheet><sheetData><row><c t="inlineStr"><is><t>Código (Sint.)</t></is></c><c t="inlineStr"><is><t>Cor</t></is></c><c t="inlineStr"><is><t>Pantone</t></is></c></row><row><c t="inlineStr"><is><t>AZ-072</t></is></c><c t="inlineStr"><is><t>AZUL</t></is></c><c s="1"><v>72</v></c></row></sheetData></worksheet>');$zip->close();
try{$formatted=RawMaterialSpreadsheet::read($xlsx,'xlsx');}finally{unlink($xlsx);}
ink_check($formatted[0]['pantone']==='072','Excel com formato 000 mantém o zero visível no Pantone.');
require_once __DIR__.'/../app/Services/InkMaterialSchema.php';
$legacy=new PDO('sqlite::memory:');$legacy->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$legacy->exec('CREATE TABLE erp_colors(id INTEGER PRIMARY KEY,name TEXT,pantone TEXT);INSERT INTO erp_colors VALUES(1,"AZUL","072");CREATE TABLE erp_raw_materials(id INTEGER PRIMARY KEY,code TEXT,description TEXT,color_id INTEGER,is_water_based_ink INTEGER,is_solvent_based_ink INTEGER,ink_type_id INTEGER);INSERT INTO erp_raw_materials VALUES(17,"AZ-OLD","Tinta antiga",1,1,0,1)');
InkMaterialSchema::ensure($legacy);$m=$legacy->query('SELECT * FROM erp_raw_materials')->fetch(PDO::FETCH_ASSOC);
ink_check($m['ink_pantone']==='072'&&$m['ink_color']==='AZUL'&&(int)$m['id']===17&&$m['legacy_ink_id']===null,'Migração usa os dados existentes e mantém IDs internos; não inventa ID antigo.');
$before=$legacy->query('SELECT total_changes()')->fetchColumn();InkMaterialSchema::ensure($legacy);
ink_check($before===$legacy->query('SELECT total_changes()')->fetchColumn(),'Reabrir a página não reescreve o cadastro de tintas.');
echo "ink_material_fields_test: OK\n";
