<?php
/**
 * Simulate a rolling deployment where an older page still owns the former,
 * unprefixed migration entry point while the shared migration file is new.
 */
function erp_run_phase1_migrations(PDO $pdo)
{
    return 'legacy-page';
}

function erp_default_permissions(): array
{
    return ['legacy.permission' => 'Legacy permission'];
}

function erp_user_can(PDO $pdo, array $user, string $permission): bool
{
    return false;
}

function erp_audit(PDO $pdo, $userId, string $action, string $entity, $entityId, array $oldValues = [], array $newValues = [], $reason = null)
{
    return 'legacy-page';
}

require_once dirname(__DIR__) . '/erp_migrations.php';

if (!function_exists('gt_erp_run_phase1_migrations')) {
    fwrite(STDERR, "The namespaced ERP migration entry point was not loaded.\n");
    exit(1);
}

if (!function_exists('gt_erp_default_permissions')) {
    fwrite(STDERR, "The namespaced ERP permissions helper was not loaded.\n");
    exit(1);
}

if (!function_exists('gt_erp_user_can')) {
    fwrite(STDERR, "The namespaced ERP permission checker was not loaded.\n");
    exit(1);
}

if (!function_exists('gt_erp_audit')) {
    fwrite(STDERR, "The namespaced ERP audit helper was not loaded.\n");
    exit(1);
}

$reflection = new ReflectionFunction('erp_run_phase1_migrations');
if ($reflection->getFileName() !== __FILE__) {
    fwrite(STDERR, "The legacy page migration function was unexpectedly replaced.\n");
    exit(1);
}

$permissionsReflection = new ReflectionFunction('erp_default_permissions');
if ($permissionsReflection->getFileName() !== __FILE__) {
    fwrite(STDERR, "The legacy permissions helper was unexpectedly replaced.\n");
    exit(1);
}

$userCanReflection = new ReflectionFunction('erp_user_can');
if ($userCanReflection->getFileName() !== __FILE__) {
    fwrite(STDERR, "The legacy permission checker was unexpectedly replaced.\n");
    exit(1);
}

$auditReflection = new ReflectionFunction('erp_audit');
if ($auditReflection->getFileName() !== __FILE__) {
    fwrite(STDERR, "The legacy audit helper was unexpectedly replaced.\n");
    exit(1);
}

fwrite(STDOUT, "ERP migration entry points coexist during rolling deployment.\n");
