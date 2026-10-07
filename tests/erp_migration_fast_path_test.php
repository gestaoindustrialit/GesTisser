<?php
require_once dirname(__DIR__) . '/erp_migrations.php';

function migration_fast_path_assert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$database = tempnam(sys_get_temp_dir(), 'gestisser_erp_migration_');
$writer = new PDO('sqlite:' . $database);
$reader = new PDO('sqlite:' . $database);
foreach ([$writer, $reader] as $pdo) {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA busy_timeout=100');
}

$writer->exec('CREATE TABLE gestisser_erp_schema_migrations (version TEXT PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)');
$writer->prepare('INSERT INTO gestisser_erp_schema_migrations(version) VALUES (?)')->execute([GESTISSER_ERP_SCHEMA_VERSION]);

// Simulate the web backup holding SQLite's write reservation. A current schema
// must only be read, so navigating between ERP sections cannot raise SQLITE_BUSY.
$writer->exec('BEGIN IMMEDIATE');
$writer->exec('CREATE TABLE backup_write_lock (id INTEGER)');
gt_erp_run_phase1_migrations($reader);
migration_fast_path_assert(gt_erp_schema_is_current($reader), 'A versão atual da migração ERP não foi reconhecida.');
$writer->exec('ROLLBACK');

$writer = null;
$reader = null;
@unlink($database);
@unlink($database . '-wal');
@unlink($database . '-shm');

fwrite(STDOUT, "ERP migration fast path remains read-only while the database is write-locked.\n");
