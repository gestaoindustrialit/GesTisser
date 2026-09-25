<?php
$erpSource = (string) file_get_contents(__DIR__ . '/../erp.php');
$migrationSource = (string) file_get_contents(__DIR__ . '/../erp_migrations.php');
$dossierSource = (string) file_get_contents(__DIR__ . '/../production_dossier.php');
$printSource = (string) file_get_contents(__DIR__ . '/../production_dossier_print.php');
$pdfSource = (string) file_get_contents(__DIR__ . '/../app/Services/ProductionDossierPdf.php');

function article_printer_roll_check($condition, $message)
{
    if (!$condition) throw new RuntimeException($message);
}

article_printer_roll_check(strpos($migrationSource, "'printer_roll_measure' => 'TEXT'") !== false, 'A migração do rolo impressor está em falta.');
article_printer_roll_check(strpos($erpSource, "'printer_roll_measure'=>'Rolo impressor'") !== false, 'O formulário do artigo não apresenta o rolo impressor.');
article_printer_roll_check(strpos($erpSource, "'of_colors_match_technical','printer_roll_measure','pallet_dimensions'") !== false, 'O rolo impressor não é guardado no artigo.');
article_printer_roll_check(strpos($dossierSource, "'printer_roll_measure'=>'Rolo impressor'") !== false, 'A folha de acompanhamento não apresenta o rolo impressor.');
article_printer_roll_check(strpos($printSource, "printer_roll_measure") !== false, 'A impressão da OF não apresenta o rolo impressor.');
article_printer_roll_check(strpos($pdfSource, "printer_roll_measure") !== false, 'O PDF de contingência não apresenta o rolo impressor.');

echo "article_printer_roll_test: OK\n";
