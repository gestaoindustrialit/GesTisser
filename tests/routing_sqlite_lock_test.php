<?php
declare(strict_types=1);

$source = (string) file_get_contents(dirname(__DIR__) . '/app/Services/RoutingService.php');

foreach (['BEGIN IMMEDIATE', 'isDatabaseBusy', 'usleep($attempt * 100000)', '$attempt >= 3'] as $expected) {
    if (strpos($source, $expected) === false) {
        throw new RuntimeException('Falta a proteção contra bloqueios SQLite no RoutingService: ' . $expected);
    }
}

if (strpos($source, '->beginTransaction()') !== false) {
    throw new RuntimeException('O RoutingService não deve iniciar transações SQLite diferidas.');
}

$shopfloor = (string) file_get_contents(dirname(__DIR__) . '/shopfloor.php');
if (strpos($shopfloor, '->syncPendingOrderOperations()') !== false) {
    throw new RuntimeException('O Shopfloor não deve regravar todas as operações pendentes em cada abertura.');
}

echo "Proteção contra bloqueios SQLite do routing validada.\n";
