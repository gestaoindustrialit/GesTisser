<?php
require_once __DIR__.'/../app/Services/NumberSequenceService.php';

function sequence_assert($condition, $message) { if (!$condition) { fwrite(STDERR, "FAIL: $message\n"); exit(1); } }

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE erp_number_sequences(id INTEGER PRIMARY KEY,code TEXT UNIQUE,prefix TEXT,next_number INTEGER,padding INTEGER,suffix TEXT,updated_at TEXT)');
$pdo->exec("INSERT INTO erp_number_sequences(code,prefix,next_number,padding,suffix) VALUES ('work_order','OF-2026-',417,4,'')");

sequence_assert(NumberSequenceService::peek($pdo, 'work_order') === 'OF-2026-0417', 'A pré-visualização deve usar o contador configurado.');
$pdo->beginTransaction();
sequence_assert(NumberSequenceService::take($pdo, 'work_order') === 'OF-2026-0417', 'Deve reservar o número esperado.');
sequence_assert(NumberSequenceService::take($pdo, 'work_order') === 'OF-2026-0418', 'Deve avançar a sequência em cada utilização.');
$pdo->rollBack();
sequence_assert(NumberSequenceService::peek($pdo, 'work_order') === 'OF-2026-0417', 'O contador deve acompanhar a transação da criação da OF.');

echo "OK: sequência de OF validada.\n";
