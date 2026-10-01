<?php
require_once __DIR__ . '/../app/Services/ShopfloorDailyIndicators.php';

$serviceSource = (string) file_get_contents(__DIR__ . '/../app/Services/ShopfloorDailyIndicators.php');
if (preg_match('/\b(?:public|protected|private)\s+[A-Za-z_\\\\][A-Za-z0-9_\\\\]*\s+\$/', $serviceSource)) {
    throw new RuntimeException('The daily indicators service must remain compatible with PHP versions before typed properties.');
}
if (preg_match('/function\s+[A-Za-z_][A-Za-z0-9_]*\s*\([^)]*\?[A-Za-z_\\\\]/', $serviceSource)) {
    throw new RuntimeException('The daily indicators service must remain compatible with PHP 7.0 nullable parameters.');
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE shopfloor_time_entries (id INTEGER PRIMARY KEY, user_id INTEGER, entry_type TEXT, occurred_at TEXT)');
$pdo->exec('CREATE TABLE shopfloor_break_entries (id INTEGER PRIMARY KEY, user_id INTEGER, break_type TEXT, started_at TEXT, ended_at TEXT)');
$pdo->exec('CREATE TABLE erp_operation_time_entries (id INTEGER PRIMARY KEY, production_order_operation_id INTEGER, user_id INTEGER, started_at TEXT, ended_at TEXT, quantity_good REAL)');
$pdo->exec('CREATE TABLE erp_operation_execution_operators (time_entry_id INTEGER, user_id INTEGER)');
$pdo->exec('CREATE TABLE erp_operation_stoppages (id INTEGER PRIMARY KEY, time_entry_id INTEGER, started_at TEXT, ended_at TEXT)');

$pdo->exec("INSERT INTO shopfloor_time_entries VALUES (1,1,'entrada','2026-10-01 08:00:00')");
$pdo->exec("INSERT INTO shopfloor_break_entries VALUES (1,1,'Pausa','2026-10-01 09:00:00','2026-10-01 09:15:00')");
$pdo->exec("INSERT INTO shopfloor_break_entries VALUES (2,1,'Paragem','2026-10-01 10:00:00','2026-10-01 10:20:00')");
$pdo->exec("INSERT INTO erp_operation_time_entries VALUES (1,10,1,'2026-10-01 08:00:00',NULL,1245)");
$pdo->exec("INSERT INTO erp_operation_stoppages VALUES (1,1,'2026-10-01 09:00:00','2026-10-01 09:15:00')");
$pdo->exec("INSERT INTO erp_operation_stoppages VALUES (2,1,'2026-10-01 10:00:00','2026-10-01 10:20:00')");
$pdo->exec("INSERT INTO erp_operation_stoppages VALUES (3,1,'2026-10-01 11:25:00','2026-10-01 12:00:00')");

$result = (new ShopfloorDailyIndicators($pdo))->forUser(1, new DateTimeImmutable('2026-10-01 12:00:00'));
$expected = [
    'presence_seconds' => 14400,
    'worked_seconds' => 10200,
    'pause_seconds' => 900,
    'stoppage_seconds' => 1200,
    'dead_seconds' => 2100,
    'production_quantity' => 1245.0,
];
foreach ($expected as $key => $value) {
    if ($result[$key] !== $value) {
        throw new RuntimeException($key . ': expected ' . $value . ', got ' . $result[$key]);
    }
}
if (ShopfloorDailyIndicators::formatDuration($result['dead_seconds']) !== '00:35:00') {
    throw new RuntimeException('Duration formatting failed.');
}

// Two overlapping operations represent 2 chronological hours, not 3 summed hours.
$pdo->exec("INSERT INTO shopfloor_time_entries VALUES (2,2,'entrada','2026-10-01 08:00:00')");
$pdo->exec("INSERT INTO erp_operation_time_entries VALUES (2,20,2,'2026-10-01 08:00:00','2026-10-01 10:00:00',0)");
$pdo->exec("INSERT INTO erp_operation_time_entries VALUES (3,21,99,'2026-10-01 09:00:00','2026-10-01 11:00:00',0)");
$pdo->exec('INSERT INTO erp_operation_execution_operators VALUES (3,2)');
$overlapResult = (new ShopfloorDailyIndicators($pdo))->forUser(2, new DateTimeImmutable('2026-10-01 11:00:00'));
if ($overlapResult['worked_seconds'] !== 10800) {
    throw new RuntimeException('Overlapping operation intervals were counted more than once.');
}

$shopfloorSource = (string) file_get_contents(__DIR__ . '/../shopfloor.php');
foreach (['T. Presença', 'T. Trabalhado', 'T. Morto', 'Produção', 'Pausas', 'Paragens', '<h2>BH</h2>', '<h2>Férias</h2>'] as $indicatorLabel) {
    if (strpos($shopfloorSource, $indicatorLabel) === false) {
        throw new RuntimeException('Missing or reordered Shopfloor indicator: ' . $indicatorLabel);
    }
}
if (strpos($shopfloorSource, 'Gestão pessoal') !== false || strpos($shopfloorSource, 'Pedidos ligados ao módulo de RH') !== false) {
    throw new RuntimeException('The removed personal-management heading is still present.');
}

echo "shopfloor daily indicators ok\n";
