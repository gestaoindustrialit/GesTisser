<?php
declare(strict_types=1);

/**
 * Keep the BI entry point usable while the ERP helper rename is being deployed.
 *
 * PHP files can be replaced in a different order on production. During that
 * short window erp_migrations.php may expose either the new, prefixed helpers or
 * their legacy names. Calling the name directly turns that harmless mismatch
 * into a fatal error (and, with display_errors disabled, a blank page).
 */
function gt_bi_run_erp_migrations(PDO $pdo)
{
    if (function_exists('gt_erp_run_phase1_migrations')) {
        return gt_erp_run_phase1_migrations($pdo);
    }

    if (function_exists('erp_run_phase1_migrations')) {
        return erp_run_phase1_migrations($pdo);
    }

    throw new RuntimeException('O inicializador de migrações do ERP não está disponível.');
}

function gt_bi_user_can(PDO $pdo, array $user, string $permission): bool
{
    if (function_exists('gt_erp_user_can')) {
        return gt_erp_user_can($pdo, $user, $permission);
    }

    if (function_exists('erp_user_can')) {
        return erp_user_can($pdo, $user, $permission);
    }

    throw new RuntimeException('O verificador de permissões do ERP não está disponível.');
}
