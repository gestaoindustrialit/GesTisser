<?php
declare(strict_types=1);

// Processo independente: pode ser executado por cron mesmo quando o bootstrap web falha.
require_once dirname(__DIR__) . '/app/Services/BackupManager.php';

$manager = new BackupManager(dirname(__DIR__));
$loop = in_array('--daemon', $argv ?? [], true);
do {
    try {
        $result = $manager->runScheduled();
        fwrite(STDOUT, $result ? ('Backup criado: ' . $result['file'] . PHP_EOL) : ('Sem backup pendente.' . PHP_EOL));
    } catch (Throwable $e) {
        fwrite(STDERR, '[' . gmdate('c') . '] ' . $e->getMessage() . PHP_EOL);
    }
    if ($loop) sleep(60);
} while ($loop);
