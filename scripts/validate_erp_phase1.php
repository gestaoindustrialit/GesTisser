<?php
declare(strict_types=1);
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../erp_migrations.php';
erp_run_phase1_migrations($pdo);
$required = ['erp_permissions','erp_audit_log','erp_number_sequences','erp_material_features','erp_locations','erp_raw_materials','erp_finished_products','erp_product_documents','erp_stock_movements','erp_stock_balances'];
foreach ($required as $table) {
    $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=? LIMIT 1");
    $stmt->execute([$table]);
    if (!$stmt->fetchColumn()) { fwrite(STDERR, "Tabela em falta: {$table}\n"); exit(1); }
}
$permissions = (int)$pdo->query('SELECT COUNT(*) FROM erp_permissions')->fetchColumn();
if ($permissions < 25) { fwrite(STDERR, "Permissões ERP insuficientes: {$permissions}\n"); exit(1); }
try {
    $pdo->beginTransaction();
    $allowNegative = $pdo->query("SELECT value FROM erp_settings WHERE key='allow_negative_stock'")->fetchColumn() === '1';
    if (!$allowNegative) {
        throw new RuntimeException('Stock negativo não permitido.');
    }
    $pdo->rollBack();
    fwrite(STDERR, "Validação de stock negativo falhou.\n"); exit(1);
} catch (RuntimeException $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    if ($e->getMessage() !== 'Stock negativo não permitido.') { throw $e; }
}
echo "ERP Fase 1 validado: tabelas, permissões e bloqueio base de stock negativo OK.\n";
