<?php
$source = (string) file_get_contents(__DIR__ . '/../erp_technical_sheet.php');

function technical_sheet_layout_check($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

technical_sheet_layout_check(strpos($source, "app_setting(\$pdo, 'logo_report_dark'") !== false, 'A ficha não usa o logótipo configurado.');
technical_sheet_layout_check(strpos($source, "app_setting(\$pdo, 'company_address'") !== false, 'A morada da empresa está em falta.');
technical_sheet_layout_check(strpos($source, "app_setting(\$pdo, 'company_phone'") !== false, 'O telefone da empresa está em falta.');
technical_sheet_layout_check(strpos($source, "app_setting(\$pdo, 'company_email'") !== false, 'O email da empresa está em falta.');
technical_sheet_layout_check(strpos($source, 'Data: <?= h($documentDate) ?>') !== false, 'A data do documento está em falta.');
technical_sheet_layout_check(strpos($source, '@page{size:A4 portrait;margin:0}') !== false, 'O formato de impressão A4 não está definido.');
technical_sheet_layout_check(strpos($source, 'height:297mm') !== false, 'A altura da folha A4 não está limitada.');
technical_sheet_layout_check(strpos($source, 'overflow:hidden') !== false, 'A ficha não impede conteúdo numa segunda página.');
technical_sheet_layout_check(strpos($source, 'class="section artwork"') !== false, 'A área principal da maqueta está em falta.');
technical_sheet_layout_check(strpos($source, 'class="order-grid"') !== false, 'Os dados da encomenda estão em falta.');
technical_sheet_layout_check(strpos($source, 'SELECT front_colors, back_colors, pallet_weight, pallet_quantity FROM erp_finished_products') !== false, 'A ficha não recupera do artigo os dados técnicos ausentes em snapshots antigos.');
technical_sheet_layout_check(strpos($source, "['front_colors', 'back_colors']") !== false, 'A ficha não apresenta as designações das cores de impressão.');
technical_sheet_layout_check(strpos($source, 'technical_sheet_ink_designations') !== false, 'A ficha não remove o código interno das tintas.');
technical_sheet_layout_check(strpos($source, '.ink-value{display:block;font-size:6.5px') !== false, 'As designações das tintas devem usar uma fonte bastante mais pequena.');
technical_sheet_layout_check(strpos($source, "['pallet_quantity', 'pallet_weight']") !== false, 'A ficha não apresenta a quantidade e o peso previstos por palete.');
technical_sheet_layout_check(strpos($source, "code = ? AND is_active = 1") !== false, 'A ficha não recupera o código interno ativo do catálogo documental.');
technical_sheet_layout_check(strpos($source, 'Código interno do documento: <?= h($technicalSheetDocumentNumber) ?>') !== false, 'O código interno não está presente no rodapé da ficha.');
technical_sheet_layout_check(strpos($source, 'font-size:5.5px') !== false, 'O código interno da ficha deve ser apresentado em tamanho muito pequeno.');

echo "technical_sheet_print_layout_test: OK\n";
