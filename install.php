<?php
require_once __DIR__ . '/helpers.php';

$hasSqlite = extension_loaded('pdo_sqlite');
$error = null;
$success = null;

function installer_table_exists(PDO $pdo, string $tableName): bool
{
    $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = ? LIMIT 1");
    $stmt->execute([$tableName]);
    return (bool) $stmt->fetchColumn();
}

$requiredErpTables = [
    'erp_work_centers' => 'Centros de trabalho',
    'erp_operations' => 'Operações',
    'erp_shift_templates' => 'Modelos de turnos',
    'erp_shift_assignments' => 'Escalas/turnos gerados',
    'erp_customers' => 'Clientes',
    'erp_suppliers' => 'Fornecedores',
    'erp_units' => 'Unidades',
    'erp_product_types' => 'Tipos de produto',
    'erp_material_types' => 'Tipos de material',
    'erp_colors' => 'Cores',
    'erp_warehouses' => 'Armazéns',
    'erp_products' => 'Artigos/produtos',
    'erp_inventory_movements' => 'Movimentos de armazém',
    'erp_production_orders' => 'Ordens de fabrico',
    'erp_production_order_documents' => 'Documentos da OF',
    'erp_production_order_document_acknowledgements' => 'Confirmações de documentos da OF',
    'erp_production_order_operations' => 'Operações da OF',
    'erp_operation_time_entries' => 'Tempos de operação',
    'erp_production_consumptions' => 'Consumos de produção',
];
$erpInstallStatus = [];
if ($hasSqlite) {
    foreach ($requiredErpTables as $tableName => $label) {
        $erpInstallStatus[$tableName] = [
            'label' => $label,
            'exists' => installer_table_exists($pdo, $tableName),
        ];
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $hasSqlite) {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($name === '' || $email === '' || $password === '') {
        $error = 'Preencha todos os campos para criar o utilizador administrador.';
    } else {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE LOWER(TRIM(email)) <> LOWER(TRIM(?)) AND COALESCE(pin_only_login, 0) = 0');
        $stmt->execute(['shopfloor@calcadacorp.ch']);
        $usersCount = (int) $stmt->fetchColumn();

        if ($usersCount === 0) {
            $insert = $pdo->prepare('INSERT INTO users(name, username, email, password, is_admin, access_profile, is_active, must_change_password) VALUES (?, ?, ?, ?, 1, ?, 1, 0)');
            $insert->execute([$name, $email, $email, password_hash($password, PASSWORD_DEFAULT), 'Administração']);
            $adminId = (int) $pdo->lastInsertId();
            $pdo->prepare('UPDATE users SET is_admin = 0 WHERE LOWER(TRIM(email)) = LOWER(TRIM(?)) AND COALESCE(pin_only_login, 0) = 1')->execute(['shopfloor@calcadacorp.ch']);
            set_app_setting($pdo, 'hr_alerts_inline_cron_enabled', '1');
            set_app_setting($pdo, 'hr_alerts_inline_cron_runs_per_day', '1440');
            set_app_setting($pdo, 'erp_module_enabled', '1');
            log_app_event($pdo, $adminId, 'system.install', 'Instalação inicial concluída.', ['admin_email' => $email, 'erp_tables' => array_keys($requiredErpTables)]);
            $success = 'Instalação concluída. Admin criado com sucesso.';
        } else {
            $error = 'A instalação já foi executada (já existem utilizadores criados).';
        }
    }
}

$pageTitle = 'Instalação';
require __DIR__ . '/partials/header.php';
?>
<div class="row justify-content-center">
    <div class="col-lg-6">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <h1 class="h4 mb-3">Instalação inicial do TaskForce</h1>
                <p class="text-muted">Esta página prepara o sistema, cria o primeiro utilizador administrador e valida as tabelas do ERP (produção, turnos, armazém, OFs e consumos).</p>

                <?php if (!$hasSqlite): ?>
                    <div class="alert alert-danger">A extensão <code>pdo_sqlite</code> não está ativa no PHP.</div>
                <?php endif; ?>
                <?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
                <?php if ($success): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>

                <?php if ($hasSqlite): ?>
                    <div class="alert alert-light border small">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <strong>Estado das funcionalidades ERP</strong>
                            <span class="badge text-bg-success">Bootstrap automático</span>
                        </div>
                        <div class="row g-2">
                            <?php foreach ($erpInstallStatus as $tableName => $tableStatus): ?>
                                <div class="col-md-6">
                                    <span class="<?= $tableStatus['exists'] ? 'text-success' : 'text-danger' ?>">
                                        <?= $tableStatus['exists'] ? '✓' : '×' ?> <?= h($tableStatus['label']) ?>
                                    </span>
                                    <code class="text-secondary d-block"><?= h($tableName) ?></code>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>


                <?php if ($success): ?>
                    <div class="alert alert-info small">
                        <strong>Checklist recomendado para novo servidor:</strong>
                        <ol class="mb-0 mt-2 ps-3">
                            <li>Copiar a aplicação e garantir permissões de escrita no diretório do projeto.</li>
                            <li>Configurar as variáveis SMTP no ambiente (quando necessário).</li>
                            <li>Configurar os perfis dos utilizadores (incluindo <strong>Produção</strong>) e criar PINs para terminais Shopfloor.</li>
                            <li>No menu <strong>ERP</strong>, confirmar unidades, armazéns, tipos de produto/material, artigos, OFs, documentos obrigatórios, consumos e turnos.</li>
                            <li>Agendar os cron jobs:
                                <code class="d-block mt-1">* * * * * php /caminho/TaskForce/cron_hr_alerts.php >/dev/null 2>&1</code>
                                <code class="d-block">*/5 * * * * php /caminho/TaskForce/cron_daily_reports.php >/dev/null 2>&1</code>
                            </li>
                        </ol>
                    </div>
                <?php endif; ?>

                <form method="post" class="vstack gap-3">
                    <input class="form-control" name="name" placeholder="Nome do admin" required>
                    <input class="form-control" type="email" name="email" placeholder="Email do admin" required>
                    <input class="form-control" type="password" name="password" placeholder="Password" required>
                    <button class="btn btn-primary" <?= !$hasSqlite ? 'disabled' : '' ?>>Concluir instalação</button>
                </form>

                <a href="login.php" class="btn btn-link px-0 mt-3">Ir para login</a>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
