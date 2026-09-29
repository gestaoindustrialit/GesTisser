<?php
require_once __DIR__ . '/../app/Services/ProductionDossierPdf.php';

$pdfSource = (string) file_get_contents(__DIR__ . '/../app/Services/ProductionDossierPdf.php');
if (preg_match('/\)\s*:\s*void\b/', $pdfSource)) {
    throw new RuntimeException('O gerador PDF contém retornos void incompatíveis com PHP 7.0.');
}

$image = function_exists('imagecreatetruecolor') ? imagecreatetruecolor(20, 10) : null;
$jpeg = '';
if ($image) { ob_start(); imagejpeg($image); $jpeg = (string) ob_get_clean(); imagedestroy($image); }
$pdf = ProductionDossierPdf::render([
    'order' => ['order_number' => 'OF-7', 'customer_name' => 'Cliente', 'article_code' => 'ART-1', 'planned_quantity' => 100, 'status' => 'Planeada'],
    'snapshot' => ['description' => 'Saco de teste', 'material' => 'Ráfia'],
    'metrics' => ['good' => 90, 'rejected' => 2],
    'operations' => [['sequence_no' => 10, 'name' => 'Corte', 'status' => 'Planeada']],
], $jpeg === '' ? '' : 'data:image/jpeg;base64,' . base64_encode($jpeg), 'DOC-TEST-009');
if (strpos($pdf, 'DOC-TEST-009') === false) throw new RuntimeException('Código documental em falta no PDF de fallback.');
if (strpos($pdf, '%PDF-1.4') !== 0 || strpos($pdf, 'xref') === false || strpos($pdf, 'OF-7') === false) {
    throw new RuntimeException('O fallback não produziu um PDF válido do dossier.');
}
if ($jpeg !== '' && strpos($pdf, '/Subtype /Image') === false) throw new RuntimeException('A imagem do artigo não foi incorporada no PDF da OF.');
if (strpos($pdf, 'FOLHA DE ACOMPANHAMENTO') === false || strpos($pdf, 'REGISTO DE PRODUCAO') === false) throw new RuntimeException('O PDF não tem a estrutura gráfica da folha de acompanhamento.');
foreach (['QUANTIDADE BOA', 'DESPERDICIO', 'EFICIENCIA'] as $removedMetric) {
    if (strpos($pdf, $removedMetric) !== false) throw new RuntimeException('A métrica removida ainda aparece no PDF: ' . $removedMetric);
}
if (strpos($pdfSource, 'barcode128') === false) throw new RuntimeException('O fallback não inclui o código de barras da OF.');
$pngPath=__DIR__.'/../docs/mapper-reference/ui/assets/logo-tisser-blue.png';
$pngLogo='data:image/png;base64,'.base64_encode((string)file_get_contents($pngPath));
$pngPdf=ProductionDossierPdf::render(['order'=>['order_number'=>'PNG-1'],'snapshot'=>[],'operations'=>[]],'','DOC-PNG-001',$pngLogo);
if(strpos($pngPdf,'/Logo ')===false||(strpos($pngPdf,'/FlateDecode')===false&&strpos($pngPdf,'/DCTDecode')===false))throw new RuntimeException('O fallback PDF não incorporou o logótipo PNG sem depender de GD.');
$pngMethod=(new ReflectionClass('ProductionDossierPdf'))->getMethod('pngForPdf');
$embeddedPng=$pngMethod->invoke(null,(string)file_get_contents($pngPath));
if(($embeddedPng['filter']??'')!=='FlateDecode'||empty($embeddedPng['data']))throw new RuntimeException('A incorporação nativa do logótipo PNG não funciona sem GD.');
$pngChunk=function($name,$data){return pack('N',strlen($data)).$name.$data.pack('N',crc32($name.$data));};
$indexedPng="\x89PNG\r\n\x1a\n".$pngChunk('IHDR',pack('NNCCCCC',2,1,8,3,0,0,0)).$pngChunk('PLTE',"\x00\x00\x00\x00\x66\x99").$pngChunk('tRNS',"\xFF\x80").$pngChunk('IDAT',gzcompress("\x00\x00\x01")).$pngChunk('IEND','');
$embeddedIndexedPng=$pngMethod->invoke(null,$indexedPng);
if(($embeddedIndexedPng['filter']??'')!=='FlateDecode'||($embeddedIndexedPng['width']??0)!==2||empty($embeddedIndexedPng['data']))throw new RuntimeException('O fallback PDF não incorpora logótipos PNG com paleta de cores.');

$dossier = (string) file_get_contents(__DIR__ . '/../production_dossier.php');
$print = (string) file_get_contents(__DIR__ . '/../production_dossier_print.php');
$sheet = (string) file_get_contents(__DIR__ . '/../erp_technical_sheet.php');
if (strpos($dossier, 'ProductionDossierPdf::render($d,$mainDocumentThumbnail,$productionDossierDocumentNumber,$productionCompanyPdfLogo,$productionCompanyName)') === false) throw new RuntimeException('Fallback PDF não está ligado ao logótipo raster da empresa.');
if (strpos($pdfSource, "157, 811, 16, 'FOLHA DE ACOMPANHAMENTO'") === false) throw new RuntimeException('O título do fallback pode sobrepor-se ao logótipo.');
if (strpos($pdfSource, 'self::text($content,$x+7,$y+5,9,$title') === false) throw new RuntimeException('Os títulos do fallback não estão alinhados à esquerda.');
foreach (['CARACTERISTICAS DO SACO', 'proof_reference', "['SEQ.','OPERACAO','MAQUINA']"] as $requiredPrintField) {
    if (strpos($pdfSource, $requiredPrintField) === false) throw new RuntimeException('Campo em falta no fallback: ' . $requiredPrintField);
}
if (strpos($dossier, 'catch(Throwable $e)') === false || strpos($dossier, 'Output($filename,\'S\')') === false) throw new RuntimeException('O PDF da OF não tem recuperação segura em caso de falha do mPDF.');
if (strpos($dossier, 'onclick="window.print()"') !== false) throw new RuntimeException('O botão Imprimir ainda aparece no dossier.');
foreach (['Folha de Acompanhamento', 'Dados principais da encomenda', 'Identificação do cliente e do artigo', 'Maqueta do artigo / Referência visual', 'Registo de produção', 'Observações'] as $section) {
    if (strpos($print, $section) === false) throw new RuntimeException('Secção em falta na folha de acompanhamento: ' . $section);
}
if (strpos($print, '$productionOrderFrontColors') === false || strpos($print, '$productionOrderBackColors') === false) throw new RuntimeException('A folha não usa as cores próprias da OF.');
foreach (['printer_roll_measure', 'pd_barcode128_html', '$productionCompanyLogo'] as $field) {
    if (strpos($print, $field) === false) throw new RuntimeException('Dado operacional em falta na folha: ' . $field);
}
if (strpos($dossier, 'ArticleDocument::thumbnailUrl') === false) throw new RuntimeException('Thumbnail em falta no dossier.');
if (strpos($sheet, '<object') !== false || strpos($sheet, 'target="_blank"') !== false) throw new RuntimeException('A ficha técnica ainda contém links/objetos interativos.');
if (strpos($sheet, 'MAQUETA DO ARTIGO') === false || strpos($sheet, 'ArticleDocument::thumbnailUrl') === false) throw new RuntimeException('Maqueta em falta na ficha técnica.');

echo "production_dossier_pdf_test: OK\n";
