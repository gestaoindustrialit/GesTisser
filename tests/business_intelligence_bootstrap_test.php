<?php
declare(strict_types=1);

/* Simulate a rolling deployment in which the shared ERP file is still old. */
function erp_run_phase1_migrations(PDO $pdo)
{
    $GLOBALS['bi_migrations_ran'] = true;
}

function erp_user_can(PDO $pdo, array $user, string $permission): bool
{
    return $permission === 'erp.bi.view' && (int) ($user['id'] ?? 0) === 7;
}

require_once dirname(__DIR__) . '/app/Services/BusinessIntelligenceBootstrap.php';

$pdo = new PDO('sqlite::memory:');
gt_bi_run_erp_migrations($pdo);

if (empty($GLOBALS['bi_migrations_ran'])) {
    throw new RuntimeException('O BI não recorreu ao inicializador legado durante a atualização.');
}
if (!gt_bi_user_can($pdo, ['id' => 7], 'erp.bi.view')) {
    throw new RuntimeException('O BI não recorreu ao verificador de permissões legado.');
}
if (gt_bi_user_can($pdo, ['id' => 7], 'erp.bi.financial')) {
    throw new RuntimeException('O resultado do verificador de permissões legado não foi respeitado.');
}

echo "business_intelligence_bootstrap_test: OK\n";
