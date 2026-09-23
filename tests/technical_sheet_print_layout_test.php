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

echo "technical_sheet_print_layout_test: OK\n";
