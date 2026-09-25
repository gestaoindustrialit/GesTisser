<?php
$erp = (string) file_get_contents(__DIR__ . '/../erp.php');
$dossier = (string) file_get_contents(__DIR__ . '/../production_dossier.php');
$print = (string) file_get_contents(__DIR__ . '/../production_dossier_print.php');

foreach (['proof_number', 'planned_pallets', 'pallet_type'] as $field) {
    if (strpos($erp, 'name="' . $field . '"') === false) throw new RuntimeException('Campo em falta na criação da OF: ' . $field);
    if (strpos($erp, "'" . $field . "'=>") === false) throw new RuntimeException('Campo não guardado no snapshot da OF: ' . $field);
    if (strpos($print, "['_order']['" . $field . "']") === false) throw new RuntimeException('Campo em falta na impressão da OF: ' . $field);
}
if (strpos($dossier, 'function pd_barcode39_html') === false || strpos($print, 'pd_barcode39_html($o[\'order_number\'])') === false) {
    throw new RuntimeException('Código de barras da OF em falta.');
}
foreach (['Quantidade boa', 'Desperdício', 'Eficiência'] as $removed) {
    if (strpos($print, $removed) !== false) throw new RuntimeException('Indicador removido ainda presente na impressão: ' . $removed);
}
if (strpos($print, 'background:#075f4d') !== false || strpos($print, 'color:#075f4d') !== false) {
    throw new RuntimeException('A folha de acompanhamento ainda contém o tema verde antigo.');
}

echo "production_order_print_fields_test: OK\n";
