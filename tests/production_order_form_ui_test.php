<?php
$source = (string) file_get_contents(__DIR__ . '/../erp.php');

function production_order_ui_check($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

production_order_ui_check(strpos($source, 'data-bs-target="#new-work-order-form"') !== false, 'The work-order form toggle is missing.');
production_order_ui_check(strpos($source, 'aria-expanded="false"') !== false, 'The work-order form must load collapsed.');
production_order_ui_check(strpos($source, '<div class="collapse" id="new-work-order-form">') !== false, 'The work-order form is not inside a collapsed panel.');
production_order_ui_check(strpos($source, 'Ficha detalhada de OF e edição de custos') === false, 'The detailed prototype block must be removed.');
production_order_ui_check(strpos($source, 'Protótipo funcional de dados') === false, 'The prototype badge must be removed.');

echo "production_order_form_ui_test: OK\n";
