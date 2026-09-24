<?php
/**
 * Simulate a rolling deployment where an older page still owns the former,
 * unprefixed migration entry point while the shared migration file is new.
 */
function erp_run_phase1_migrations(PDO $pdo)
{
    return 'legacy-page';
}

require_once dirname(__DIR__) . '/erp_migrations.php';

if (!function_exists('gt_erp_run_phase1_migrations')) {
    fwrite(STDERR, "The namespaced ERP migration entry point was not loaded.\n");
    exit(1);
}

$reflection = new ReflectionFunction('erp_run_phase1_migrations');
if ($reflection->getFileName() !== __FILE__) {
    fwrite(STDERR, "The legacy page migration function was unexpectedly replaced.\n");
    exit(1);
}

fwrite(STDOUT, "ERP migration entry points coexist during rolling deployment.\n");
