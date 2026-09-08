<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/app/Services/CustomerSpreadsheet.php';

$readerSource=file_get_contents(dirname(__DIR__).'/app/Services/ArticleSpreadsheet.php');
if ($readerSource===false || preg_match('/function\s+findHeaderRow\s*\([^)]*\)\s*:\s*\?/', $readerSource)) {
    throw new RuntimeException('O leitor contém um tipo de retorno nullable incompatível com o PHP do servidor.');
}
$customerReaderSource=file_get_contents(dirname(__DIR__).'/app/Services/CustomerSpreadsheet.php');
if ($customerReaderSource===false || preg_match('/\b(?:private|protected|public)\s+const\b/', $customerReaderSource)) {
    throw new RuntimeException('O leitor de clientes contém uma constante com visibilidade incompatível com o PHP do servidor.');
}

function test_xlsx_cell(string $value, bool $richText = false): string
{
    if ($richText) {
        $middle=(int)floor(strlen($value)/2);
        return '<c t="inlineStr"><is><r><t>'.htmlspecialchars(substr($value,0,$middle), ENT_XML1 | ENT_QUOTES, 'UTF-8').'</t></r><r><t>'.htmlspecialchars(substr($value,$middle), ENT_XML1 | ENT_QUOTES, 'UTF-8').'</t></r></is></c>';
    }
    return '<c t="inlineStr"><is><t>'.htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</t></is></c>';
}

$tmp=tempnam(sys_get_temp_dir(), 'customer_reader_');
$zip=new ZipArchive();
$zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Clientes" sheetId="1" r:id="rId7"/></sheets></workbook>');
$zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId7" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/clientes.xml"/></Relationships>');
$headers=["\xEF\xBB\xBFcódigo\xC2\xA0",'nome','nif'];
$values=['331','4000K, LDA','515576298'];
$title='<row r="1">'.test_xlsx_cell('Lista de clientes').'</row>';
$headerCells=[]; foreach ($headers as $header) { $headerCells[]=test_xlsx_cell($header, true); }
$rows=$title.'<row r="2">'.implode('',$headerCells).'</row><row r="3">'.implode('',array_map('test_xlsx_cell',$values)).'</row>';
$zip->addFromString('xl/worksheets/clientes.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$rows.'</sheetData></worksheet>');
$zip->close();

try {
    $customers=CustomerSpreadsheet::read($tmp, 'xlsx');
    if (count($customers)!==1 || $customers[0]['codigo']!=='331' || $customers[0]['nome']!=='4000K, LDA') {
        throw new RuntimeException('A folha de clientes não foi lida corretamente.');
    }
    echo "Leitura de clientes em folha Excel com cabeçalhos normalizados: OK.\n";
} finally {
    unlink($tmp);
}
