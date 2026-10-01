<?php
$erp = (string) file_get_contents(__DIR__ . '/../erp.php');

function production_order_quick_print_check($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$quickPrintLink = 'href="production_dossier.php?id=<?=(int)$order[\'id\']?>&amp;format=pdf"';

production_order_quick_print_check(
    strpos($erp, $quickPrintLink) !== false,
    'O botão OF não abre diretamente o PDF da ordem de fabrico.'
);
production_order_quick_print_check(
    strpos($erp, 'erp_technical_sheet.php?id=<?=(int)$order[\'technical_sheet_id\']?>') < strpos($erp, $quickPrintLink),
    'O botão OF deve aparecer ao lado e depois do botão Ficha.'
);

echo "production_order_quick_print_test: OK\n";
