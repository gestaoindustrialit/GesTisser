<?php
$printSource = (string) file_get_contents(__DIR__ . '/../production_dossier_print.php');
$controllerSource = (string) file_get_contents(__DIR__ . '/../production_dossier.php');
$pdfSource = (string) file_get_contents(__DIR__ . '/../app/Services/ProductionDossierPdf.php');

function production_dossier_layout_check($condition, $message)
{
    if (!$condition) throw new RuntimeException($message);
}

production_dossier_layout_check(strpos($printSource, '.report-header{height:29mm;display:grid;grid-template-columns:48mm 1fr 51mm') !== false, 'O cabeçalho deve seguir a grelha da ficha técnica.');
production_dossier_layout_check(strpos($printSource, '<h1>Folha de Acompanhamento</h1>') !== false, 'O título da folha de acompanhamento está em falta.');
production_dossier_layout_check(strpos($printSource, '<div class="order-number">OF ') !== false, 'O número da OF deve aparecer sob o título.');
production_dossier_layout_check(strpos($printSource, '.section{border:1.5px solid #111') !== false, 'As secções da OF devem seguir a grelha visual da ficha técnica.');
production_dossier_layout_check(strpos($printSource, 'background:#e6e7e8') !== false, 'Os títulos e tabelas devem usar o cinzento da ficha técnica.');
production_dossier_layout_check(strpos($printSource, 'class="document-footer"') !== false, 'O rodapé documental está em falta.');
production_dossier_layout_check(strpos($printSource, '<?=h($productionDossierDocumentNumber)?>') !== false, 'O rodapé não apresenta o código configurado do documento.');
production_dossier_layout_check(strpos($controllerSource, "execute(['production_dossier'])") !== false, 'A OF não recupera o código do catálogo documental.');
production_dossier_layout_check(strpos($pdfSource, "'Documento: ' . \$documentNumber") !== false, 'O gerador PDF de contingência não apresenta o código documental.');
production_dossier_layout_check(strpos($pdfSource, '/Encoding /WinAnsiEncoding') !== false, 'O PDF de contingência não declara a codificação necessária para caracteres portugueses.');
production_dossier_layout_check(strpos($controllerSource, '$productionOrderFrontColors=pd_order_colors($s[\'of_front_colors\']??\'\')') !== false, 'As cores de impressão não são obtidas dos campos da OF.');

echo "production_dossier_print_layout_test: OK\n";
