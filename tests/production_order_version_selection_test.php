<?php
$erp = (string) file_get_contents(__DIR__ . '/../erp.php');
$service = (string) file_get_contents(__DIR__ . '/../production_dossier_service.php');

function production_order_version_check($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

production_order_version_check(strpos($erp, 'name="technical_sheet_version_id"') !== false, 'O formulário não permite escolher a versão do artigo.');
production_order_version_check(strpos($erp, 'foreach($technicalSheetVersions as $version)') !== false, 'A lista não apresenta todas as versões existentes.');
production_order_version_check(strpos($erp, 'name="planned_pallets"') === false, 'A quantidade prevista de paletes ainda pode ser introduzida manualmente.');
production_order_version_check(strpos($erp, "ceil(\$quantity/\$quantityPerPallet)") !== false, 'A quantidade de paletes não é calculada no servidor.');
production_order_version_check(strpos($erp, 'Math.ceil(quantity/perPallet)') !== false, 'A pré-visualização da quantidade de paletes não é automática.');
production_order_version_check(strpos($service, 'WHERE id=? AND finished_product_id=?') !== false, 'O serviço não valida a versão selecionada contra o artigo.');

echo "production_order_version_selection_test: OK\n";
