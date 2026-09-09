<?php
declare(strict_types=1);

$settingsPage = file_get_contents(__DIR__ . '/../erp_settings.php');
if ($settingsPage === false) {
    fwrite(STDERR, "Não foi possível ler erp_settings.php.\n");
    exit(1);
}

$requiredFragments = [
    'Configuração das operações',
    'erp_operations.php?id=0',
    'erp_operations.php?id=<?= (int) $operation[\'id\'] ?>',
    'operation_type',
    'production_unit',
    'min_operators',
    'default_work_center_id',
];

foreach ($requiredFragments as $fragment) {
    if (strpos($settingsPage, $fragment) === false) {
        fwrite(STDERR, 'Falta a integração da configuração de operações: ' . $fragment . "\n");
        exit(1);
    }
}

echo "Configuração ERP permite aceder à criação e edição dos campos das operações.\n";
