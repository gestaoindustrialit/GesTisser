<?php
$printSource = (string) file_get_contents(__DIR__ . '/../production_dossier_print.php');
$controllerSource = (string) file_get_contents(__DIR__ . '/../production_dossier.php');
$pdfSource = (string) file_get_contents(__DIR__ . '/../app/Services/ProductionDossierPdf.php');

function production_dossier_layout_check($condition, $message)
{
    if (!$condition) throw new RuntimeException($message);
}

production_dossier_layout_check(strpos($printSource, '.header{background:#075f4d') !== false, 'O cabeçalho original da OF deve ser preservado.');
production_dossier_layout_check(strpos($printSource, '.section{margin:3.5mm 0;border:1.5px solid #111') !== false, 'As secções da OF devem seguir a grelha visual da ficha técnica.');
production_dossier_layout_check(strpos($printSource, 'background:#e6e7e8') !== false, 'Os títulos e tabelas devem usar o cinzento da ficha técnica.');
production_dossier_layout_check(strpos($printSource, 'class="footer-table"') !== false, 'O rodapé ISO deve usar uma tabela.');
production_dossier_layout_check(strpos($printSource, '<?=h($productionDossierDocumentNumber)?>') !== false, 'O rodapé não apresenta o código configurado do documento.');
production_dossier_layout_check(strpos($controllerSource, "execute(['production_dossier'])") !== false, 'A OF não recupera o código do catálogo documental.');
production_dossier_layout_check(strpos($pdfSource, "'Documento: ' . \$documentNumber") !== false, 'O gerador PDF de contingência não apresenta o código documental.');

echo "production_dossier_print_layout_test: OK\n";
