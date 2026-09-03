<?php

declare(strict_types=1);

$machinePage = dirname(__DIR__) . '/erp_machines.php';
$source = file_get_contents($machinePage);

if ($source === false) {
    fwrite(STDERR, "Não foi possível ler erp_machines.php.\n");
    exit(1);
}

foreach (token_get_all($source) as $token) {
    $isArrowFunction = is_array($token)
        && ((defined('T_FN') && $token[0] === constant('T_FN'))
            || ($token[0] === T_STRING && strtolower($token[1]) === 'fn'));
    if ($isArrowFunction) {
        fwrite(STDERR, "erp_machines.php contém uma arrow function incompatível (fn) na linha {$token[2]}.\n");
        exit(1);
    }
}

if (strpos($source, 'gt_machine_ids($machines)') === false) {
    fwrite(STDERR, "A extração compatível dos identificadores das máquinas não está a ser utilizada.\n");
    exit(1);
}

fwrite(STDOUT, "Compatibilidade sintática de erp_machines.php validada.\n");
