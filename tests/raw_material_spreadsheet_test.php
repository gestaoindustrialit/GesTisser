<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/Services/RawMaterialSpreadsheet.php';

function assert_same($expected,$actual,string$message):void{if($expected!==$actual)throw new RuntimeException($message.' Esperado '.var_export($expected,true).', recebido '.var_export($actual,true));}

$erpSource=file_get_contents(dirname(__DIR__).'/erp.php');
if($erpSource===false||strpos($erpSource,"require_once __DIR__ . '/app/Services/RawMaterialSpreadsheet.php';")===false)throw new RuntimeException('O ERP deve carregar explicitamente o serviço de matérias-primas, tal como os restantes importadores.');

// Compatibility with previous templates (categoria, without grupo_produto) is retained.
$tmp=tempnam(sys_get_temp_dir(),'raw_material_csv_');file_put_contents($tmp,"codigo;descricao;categoria;unidade;stock_minimo;alertas_ativos\nMP-01;Polipropileno;raw_material;KG;100;Sim\n");
try{$rows=RawMaterialSpreadsheet::read($tmp,'csv');}finally{@unlink($tmp);}
assert_same('MP-01',$rows[0]['codigo'],'A folha antiga não foi interpretada corretamente.');

// Files exported by other ERPs commonly use descriptive headings instead of
// the short headings from our downloadable model.
$tmp=tempnam(sys_get_temp_dir(),'raw_material_alias_csv_');file_put_contents($tmp,"Exportação de materiais\nCódigo matéria-prima;Designação;Unidade\nMP-02;Polietileno;KG\n");
try{$rows=RawMaterialSpreadsheet::read($tmp,'csv');}finally{@unlink($tmp);}
assert_same('MP-02',$rows[0]['codigo'],'O alias do código da matéria-prima não foi reconhecido.');
assert_same('Polietileno',$rows[0]['descricao'],'O alias da descrição da matéria-prima não foi reconhecido.');

$tmp=tempnam(sys_get_temp_dir(),'raw_material_english_csv_');file_put_contents($tmp,"code,description,unidade\nMP-03,Masterbatch,KG\n");
try{$rows=RawMaterialSpreadsheet::read($tmp,'csv');}finally{@unlink($tmp);}
assert_same('MP-03',$rows[0]['codigo'],'O cabeçalho code não foi reconhecido.');
assert_same('Masterbatch',$rows[0]['descricao'],'O cabeçalho description não foi reconhecido.');

$tmp=tempnam(sys_get_temp_dir(),'raw_material_windows_csv_');
$windowsCsv=iconv('UTF-8','Windows-1252',"Código;Descrição;Observações\nMP-04;Polietileno reciclado;Produção\n");
if($windowsCsv===false)throw new RuntimeException('Não foi possível preparar o CSV Windows-1252 de teste.');
file_put_contents($tmp,$windowsCsv);
try{$rows=RawMaterialSpreadsheet::read($tmp,'csv');}finally{@unlink($tmp);}
assert_same('MP-04',$rows[0]['codigo'],'O código com acento num CSV Windows-1252 não foi reconhecido.');
assert_same('Polietileno reciclado',$rows[0]['descricao'],'A descrição com acento num CSV Windows-1252 não foi reconhecida.');
assert_same('Produção',$rows[0]['observacoes'],'Os valores do CSV Windows-1252 não foram convertidos para UTF-8.');

$templateHeaders=RawMaterialSpreadsheet::templateColumns();
assert_same(count($templateHeaders),count(array_unique(array_values(RawMaterialSpreadsheet::columns()))),'O modelo não deve repetir aliases equivalentes.');
assert_same('grupo_produto',$templateHeaders[2]??null,'O grupo_produto deve ser a terceira coluna do modelo e não pode ser removido pelos aliases de importação.');
assert_same(true,in_array('categoria',$templateHeaders,true),'A coluna de categoria legada deve continuar disponível no modelo.');

foreach(['matéria-prima','Materia Prima','materia prima','MATÉRIA PRIMA']as$value)assert_same('raw_material',RawMaterialSpreadsheet::normalizeProductGroup($value),'Falhou a normalização de matéria-prima.');
foreach(['subsidiário','Subsidiario','SUBSIDIÁRIO']as$value)assert_same('subsidiary',RawMaterialSpreadsheet::normalizeProductGroup($value),'Falhou a normalização de subsidiário.');
assert_same(null,RawMaterialSpreadsheet::normalizeProductGroup(''),'Uma célula vazia deve ser null.');
assert_same(null,RawMaterialSpreadsheet::normalizeProductGroup('Inexistente'),'Um grupo desconhecido não pode ser inventado.');

// Real XLSX container: first sheet is informational, useful sheet is selected by its headers.
$tmp=tempnam(sys_get_temp_dir(),'raw_material_xlsx_');$zip=new ZipArchive();$zip->open($tmp,ZipArchive::CREATE|ZipArchive::OVERWRITE);
$zip->addFromString('[Content_Types].xml','<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
$zip->addFromString('xl/workbook.xml','<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Notas" sheetId="1" r:id="rId1"/><sheet name="Dados" sheetId="2" r:id="rId2"/></sheets></workbook>');
$zip->addFromString('xl/_rels/workbook.xml.rels','<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Target="worksheets/sheet2.xml"/></Relationships>');
$cell=static fn(string$value):string=>'<c t="inlineStr"><is><t>'.htmlspecialchars($value,ENT_XML1|ENT_QUOTES,'UTF-8').'</t></is></c>';
$zip->addFromString('xl/worksheets/sheet1.xml','<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row>'.$cell('Instruções').'</row></sheetData></worksheet>');
$headers=['codigo','descricao','grupo_produto','largura','observacoes'];$data=[['0007','Ráfia','Materia Prima','1,25',''],['0008','Tinta azul','SUBSIDIÁRIO','2.50','00009']];$xml='<row>'.implode('',array_map($cell,$headers)).'</row>';foreach($data as$row)$xml.='<row>'.implode('',array_map($cell,$row)).'</row>';
$zip->addFromString('xl/worksheets/sheet2.xml','<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$xml.'</sheetData></worksheet>');$zip->close();
try{$rows=RawMaterialSpreadsheet::read($tmp,'xlsx');}finally{@unlink($tmp);}
assert_same('0007',$rows[0]['codigo'],'O código perdeu zeros à esquerda.');assert_same(null,$rows[0]['observacoes'],'A célula vazia não foi convertida para null.');
assert_same('raw_material',RawMaterialSpreadsheet::normalizeProductGroup($rows[0]['grupo_produto']),'A linha de ráfia não ficou em Materia Prima.');
assert_same('subsidiary',RawMaterialSpreadsheet::normalizeProductGroup($rows[1]['grupo_produto']),'A linha de tinta não ficou em Subsidiario.');
assert_same(1.25,(float)str_replace(',','.',(string)$rows[0]['largura']),'O decimal com vírgula não foi aceite.');
assert_same(2.5,(float)str_replace(',','.',(string)$rows[1]['largura']),'O decimal com ponto não foi aceite.');
echo "XLSX validado: ráfia=Materia Prima; tinta=Subsidiario.\n";
