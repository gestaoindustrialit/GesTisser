<?php
require_once __DIR__.'/helpers.php';
require_once __DIR__.'/app/Services/CustomerSpreadsheet.php';
require_login();
$headers=array_keys(CustomerSpreadsheet::columns());
$values=['codigo'=>'CLI-001','nome'=>'Cliente Exemplo, Lda.','nif'=>'500000000','pais'=>'Portugal','prefixo_pais'=>'PT','telefone'=>'229000000','telemovel'=>'910000000','email'=>'geral@cliente.pt','morada_1'=>'Rua Exemplo, 1','cidade'=>'Porto','codigo_postal'=>'4000-000','contacto'=>'Ana Silva','vendedor'=>'Comercial Norte','desconto_percentagem'=>'0','saldo'=>'0','plafond'=>'5000','ativo'=>'Sim'];
$example=[]; foreach($headers as $header){$example[]=$values[$header]??'';}
function customer_xlsx_cell(string $value): string { return '<c t="inlineStr"><is><t>'.htmlspecialchars($value,ENT_XML1|ENT_QUOTES,'UTF-8').'</t></is></c>'; }
$rows='<row>'.implode('',array_map('customer_xlsx_cell',$headers)).'</row><row>'.implode('',array_map('customer_xlsx_cell',$example)).'</row>';
$files=[
'[Content_Types].xml'=>'<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
'_rels/.rels'=>'<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
'xl/workbook.xml'=>'<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Clientes" sheetId="1" r:id="rId1"/></sheets></workbook>',
'xl/_rels/workbook.xml.rels'=>'<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
'xl/worksheets/sheet1.xml'=>'<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$rows.'</sheetData></worksheet>'
];
$tmp=tempnam(sys_get_temp_dir(),'customers_'); $zip=new ZipArchive(); $zip->open($tmp,ZipArchive::CREATE|ZipArchive::OVERWRITE); foreach($files as $name=>$body){$zip->addFromString($name,$body);} $zip->close();
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); header('Content-Disposition: attachment; filename="modelo_importacao_clientes.xlsx"'); header('Content-Length: '.filesize($tmp)); readfile($tmp); unlink($tmp);
