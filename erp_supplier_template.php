<?php
require_once __DIR__.'/helpers.php';
require_once __DIR__.'/app/Services/SupplierSpreadsheet.php';
require_login();
$headers=SupplierSpreadsheet::templateColumns();
$values=['codigo'=>'FOR-001','nome'=>'Fornecedor Exemplo, Lda.','nif'=>'500000000','pais'=>'Portugal','morada_1'=>'Rua Exemplo, 1','codigo_postal'=>'4000-000','telefone'=>'229000000','telemovel'=>'910000000','email'=>'compras@fornecedor.pt','contacto'=>'Ana Silva','site'=>'https://fornecedor.pt','desconto'=>'2,5','plafond'=>'5000','certificado_pefc'=>'Não','controlo_stocks_pedidos'=>'Não','incluir_osaft'=>'Sim','observacoes'=>'','ativo'=>'Sim'];
function supplier_xls_cell(string $value): string{return '<Cell><Data ss:Type="String">'.htmlspecialchars($value,ENT_XML1|ENT_QUOTES,'UTF-8').'</Data></Cell>';}
$headerCells=$valueCells=[];foreach($headers as $header){$headerCells[]=supplier_xls_cell($header);$valueCells[]=supplier_xls_cell($values[$header]??'');}
$document='<?xml version="1.0" encoding="UTF-8"?><?mso-application progid="Excel.Sheet"?><Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"><Worksheet ss:Name="Fornecedores"><Table><Row>'.implode('',$headerCells).'</Row><Row>'.implode('',$valueCells).'</Row></Table></Worksheet></Workbook>';
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');header('Content-Disposition: attachment; filename="modelo_importacao_fornecedores.xls"');header('Content-Length: '.strlen($document));echo $document;
