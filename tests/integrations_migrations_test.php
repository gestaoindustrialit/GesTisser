<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap/config.php';
require_once dirname(__DIR__) . '/integrations_migrations.php';

function integration_migration_assert(bool $condition, string $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

integration_migration_assert(
    !(new ReflectionFunction('integrations_migrate'))->hasReturnType(),
    'The migration function must remain compatible with runtimes that cannot enforce a void return type.'
);

integrations_migrate($pdo);

$objects = $pdo->query(
    "SELECT name FROM sqlite_master WHERE name LIKE 'integration%' OR name LIKE 'idx_integration%' ORDER BY name"
)->fetchAll(PDO::FETCH_COLUMN);
integration_migration_assert(in_array('integrations', $objects, true), 'The integrations table was not created.');
integration_migration_assert(in_array('integration_flows', $objects, true), 'The flows table was not created.');
integration_migration_assert(in_array('idx_integration_flows_due', $objects, true), 'The due-flows index was not created.');

// A second call must only inspect the schema, without opening a write transaction.
integrations_migrate($pdo);
integration_migration_assert(!$pdo->inTransaction(), 'The schema check left a transaction open.');

// Migration state is kept per connection, not globally for the PHP process.
$secondPdo = new PDO('sqlite::memory:');
$secondPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
integrations_migrate($secondPdo);
integration_migration_assert(
    (bool) $secondPdo->query("SELECT 1 FROM sqlite_master WHERE name = 'integrations'")->fetchColumn(),
    'A second database connection was not migrated.'
);

echo "Integration migrations: OK\n";
