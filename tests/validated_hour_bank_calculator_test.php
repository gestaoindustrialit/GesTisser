<?php

require_once __DIR__ . '/../app/Services/ValidatedHourBankCalculator.php';

function validated_bh_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE shopfloor_time_entries (user_id INTEGER, entry_type TEXT, occurred_at TEXT, validated_at TEXT)');
$pdo->exec('CREATE TABLE shopfloor_break_entries (user_id INTEGER, started_at TEXT, ended_at TEXT)');
$pdo->exec('CREATE TABLE shopfloor_bh_overrides (user_id INTEGER, work_date TEXT, bh_minutes INTEGER)');

$insertEntry = $pdo->prepare('INSERT INTO shopfloor_time_entries VALUES (?, ?, ?, ?)');
foreach ([
    [1, 'entrada', '2026-09-01 08:00:00', '2026-09-02 10:00:00'],
    [1, 'saida', '2026-09-01 17:00:00', '2026-09-02 10:00:00'],
    [1, 'entrada', '2026-09-02 08:00:00', '2026-09-03 10:00:00'],
    [1, 'saida', '2026-09-02 15:00:00', '2026-09-03 10:00:00'],
    [1, 'entrada', '2026-09-03 08:00:00', null],
    [1, 'saida', '2026-09-03 18:00:00', null],
] as $entry) {
    $insertEntry->execute($entry);
}
$pdo->exec("INSERT INTO shopfloor_break_entries VALUES (1, '2026-09-01 12:00:00', '2026-09-01 13:00:00')");

// First day is exactly 8 effective hours; second is one hour short. The open,
// unvalidated third day must not leak into the Shopfloor balance.
validated_bh_assert(
    ValidatedHourBankCalculator::calculateMinutes($pdo, 1) === -60,
    'O BH deve somar apenas dias integralmente validados e descontar pausas.'
);

$pdo->exec("INSERT INTO shopfloor_bh_overrides VALUES (1, '2026-09-02', -30)");
validated_bh_assert(
    ValidatedHourBankCalculator::calculateMinutes($pdo, 1) === -30,
    'O ajuste manual deve substituir o BH calculado do dia validado.'
);

validated_bh_assert(
    ValidatedHourBankCalculator::calculateMinutes($pdo, 2) === null,
    'Sem dias validados deve ser possível usar o saldo inicial guardado.'
);

echo "validated_hour_bank_calculator_test: ok\n";
