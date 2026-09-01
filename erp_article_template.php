<?php
require_once __DIR__.'/helpers.php';
require_once __DIR__.'/app/Services/ArticleSpreadsheet.php';
require_login();
$headers=array_keys(ArticleSpreadsheet::columns());
$example=array_fill(0,count($headers),'');
foreach (['codigo'=>'ART-001','descricao'=>'Saco exemplo 50 x 90','cliente'=>'Cliente Exemplo','largura'=>'50','comprimento'=>'90','gramagem'=>'60','material'=>'100% PP','microperfuracao'=>'Nao'] as $key=>$value) { $example[array_search($key,$headers,true)]=$value; }
function xlsx_cell(string $value): string { return '<c t="inlineStr"><is><t>'.htmlspecialchars($value,ENT_XML1|ENT_QUOTES,'UTF-8').'</t></is></c>'; }
$rows='<row>'.implode('',array_map('xlsx_cell',$headers)).'</row><row>'.implode('',array_map('xlsx_cell',$example)).'</row>';
$files=[
'[Content_Types].xml'=>'<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
'_rels/.rels'=>'<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
'xl/workbook.xml'=>'<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Artigos" sheetId="1" r:id="rId1"/></sheets></workbook>',
'xl/_rels/workbook.xml.rels'=>'<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
'xl/worksheets/sheet1.xml'=>'<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$rows.'</sheetData></worksheet>'
];
$tmp=tempnam(sys_get_temp_dir(),'articles_'); $zip=new ZipArchive(); $zip->open($tmp,ZipArchive::CREATE|ZipArchive::OVERWRITE); foreach($files as $name=>$body){$zip->addFromString($name,$body);} $zip->close();
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); header('Content-Disposition: attachment; filename="modelo_importacao_artigos.xlsx"'); header('Content-Length: '.filesize($tmp)); readfile($tmp); unlink($tmp);
