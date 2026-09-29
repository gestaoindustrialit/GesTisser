<?php
$erp = (string) file_get_contents(__DIR__ . '/../erp.php');

function production_order_quick_print_check($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$quickPrintLink = '<a class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener" href="production_dossier.php?id=<?=$sheet[\'production_order_id\']?>&amp;format=pdf" aria-label="Consultar ou imprimir a OF">OF</a>';

production_order_quick_print_check(
    strpos($erp, $quickPrintLink) !== false,
    'O botão OF não abre diretamente o PDF da ordem de fabrico.'
);
production_order_quick_print_check(
    strpos($erp, 'erp_technical_sheet.php?id=<?=$sheet[\'id\']?>') < strpos($erp, $quickPrintLink),
    'O botão OF deve aparecer ao lado e depois do botão Ficha.'
);

echo "production_order_quick_print_test: OK\n";
