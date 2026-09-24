<?php

declare(strict_types=1);

$machineFiles = [
    dirname(__DIR__) . '/erp_machines.php',
    dirname(__DIR__) . '/app/Services/MachineAttachment.php',
];

foreach ($machineFiles as $machineFile) {
    $source = file_get_contents($machineFile);
    if ($source === false) {
        fwrite(STDERR, "Não foi possível ler {$machineFile}.\n");
        exit(1);
    }

    foreach (token_get_all($source) as $token) {
        $isUnsupportedToken = is_array($token)
            && (((defined('T_FN') && $token[0] === constant('T_FN'))
                || ($token[0] === T_STRING && strtolower($token[1]) === 'fn'))
                || ($token[0] === T_STRING && strtolower($token[1]) === 'void'));
        if (!$isUnsupportedToken) {
            continue;
        }
        $relativeFile = str_replace(dirname(__DIR__) . '/', '', $machineFile);
        fwrite(STDERR, "{$relativeFile} contém sintaxe incompatível ({$token[1]}) na linha {$token[2]}.\n");
        exit(1);
    }
}

$source = file_get_contents($machineFiles[0]);
if (strpos($source, 'gt_machine_ids($machines)') === false) {
    fwrite(STDERR, "A extração compatível dos identificadores das máquinas não está a ser utilizada.\n");
    exit(1);
}

fwrite(STDOUT, "Compatibilidade sintática de erp_machines.php validada.\n");
