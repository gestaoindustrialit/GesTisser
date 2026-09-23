<?php
require_once __DIR__ . '/../app/Services/ProductionDossierPdf.php';

$pdf = ProductionDossierPdf::render([
    'order' => ['order_number' => 'OF-7', 'customer_name' => 'Cliente', 'article_code' => 'ART-1', 'planned_quantity' => 100, 'status' => 'Planeada'],
    'snapshot' => ['description' => 'Saco de teste', 'material' => 'Ráfia'],
    'metrics' => ['good' => 90, 'rejected' => 2],
    'operations' => [['sequence_no' => 10, 'name' => 'Corte', 'status' => 'Planeada']],
]);
if (strpos($pdf, '%PDF-1.4') !== 0 || strpos($pdf, 'xref') === false || strpos($pdf, 'OF-7') === false) {
    throw new RuntimeException('O fallback não produziu um PDF válido do dossier.');
}

$dossier = (string) file_get_contents(__DIR__ . '/../production_dossier.php');
$sheet = (string) file_get_contents(__DIR__ . '/../erp_technical_sheet.php');
if (strpos($dossier, 'ProductionDossierPdf::render($d)') === false) throw new RuntimeException('Fallback PDF não está ligado ao dossier.');
if (strpos($dossier, 'ArticleDocument::thumbnailUrl') === false) throw new RuntimeException('Thumbnail em falta no dossier.');
if (strpos($sheet, '<object') !== false || strpos($sheet, 'target="_blank"') !== false) throw new RuntimeException('A ficha técnica ainda contém links/objetos interativos.');
if (strpos($sheet, 'MAQUETA DO ARTIGO') === false || strpos($sheet, 'ArticleDocument::thumbnailUrl') === false) throw new RuntimeException('Maqueta em falta na ficha técnica.');

echo "production_dossier_pdf_test: OK\n";
