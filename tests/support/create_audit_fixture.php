<?php
// Invoked only inside the runner's disposable source copy, never against the application DB.
$root = realpath(__DIR__ . '/../..');
if (PHP_SAPI !== 'cli' || realpath((string)getenv('GESTISSER_TEST_FIXTURE_ROOT')) !== $root) {
    fwrite(STDERR, "Use scripts/test_php70.py to create this synthetic fixture.\n");
    exit(2);
}
if (is_file($root . '/database.sqlite')) {
    fwrite(STDERR, "The fixture requires a fresh disposable application directory.\n");
    exit(2);
}
require $root . '/config.php';
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("INSERT INTO erp_customers(code,name) VALUES('AUDIT-CUSTOMER','Cliente sintético')");
// Deliberately leave this article without an erp_products bridge to test the import blocker.
$pdo->exec("INSERT INTO erp_finished_products(code,description) VALUES('AUDIT-ARTICLE','Artigo sintético')");
$pdo->exec("INSERT INTO erp_raw_materials(code,description,roll_controlled) VALUES('AUDIT-MATERIAL','Material sintético',1)");
// The runner snapshots this database after PHP exits and releases every bootstrap cursor.
echo "Synthetic audit database prepared from the current application schema.\n";
