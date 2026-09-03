<?php
require_once __DIR__ . '/helpers.php';
require_login();

$userId = (int) $_SESSION['user_id'];
$isAdmin = is_admin($pdo, $userId);
if (!$isAdmin) {
    redirect('dashboard.php');
}

$flashSuccess = null;
$flashError = null;

$companyName = '';
$companyAddress = '';
$companyEmail = '';
$companyPhone = '';
$smtpHost = '';
$smtpPort = '587';
$smtpSecure = 'tls';
$smtpUsername = '';
$smtpPassword = '';
$smtpTimeout = '10';
$mailFromAddress = 'noreply@calcadacorp.ch';
$mailFromName = 'GesTisser';
$hrAlertsCronRunsPerDay = '1440';
$companyDailyObjective = '08:15';
$navbarLogo = null;
$reportLogo = null;
$greetingImages = ['birthday' => [], 'work_anniversary' => []];

function save_hr_greeting_image_upload(array $file, string $type)
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }
    if (!isset($file['tmp_name']) || !is_string($file['tmp_name']) || !is_file($file['tmp_name'])) {
        return null;
    }
    $allowed = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'];
    $extension = strtolower((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (!isset($allowed[$extension])) {
        return null;
    }
    $uploadDir = __DIR__ . '/assets/uploads/hr_greetings';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0775, true);
    }
    $filename = $type . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . ($extension === 'jpeg' ? 'jpg' : $extension);
    $targetPath = $uploadDir . '/' . $filename;
    if (!move_uploaded_file((string) $file['tmp_name'], $targetPath)) {
        return null;
    }
    return ['path' => 'assets/uploads/hr_greetings/' . $filename, 'mime' => $allowed[$extension], 'name' => (string) ($file['name'] ?? $filename)];
}

try {
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdmin) {
        $flashError = 'Apenas administradores podem editar os dados da empresa.';
    } elseif (($_POST['action'] ?? '') === 'save_company_profile') {
        $companyName = trim((string) ($_POST['company_name'] ?? ''));
        $companyAddress = trim((string) ($_POST['company_address'] ?? ''));
        $companyEmail = trim((string) ($_POST['company_email'] ?? ''));
        $companyPhone = trim((string) ($_POST['company_phone'] ?? ''));
        $smtpHost = trim((string) ($_POST['smtp_host'] ?? ''));
        $smtpPort = (int) ($_POST['smtp_port'] ?? 587);
        $smtpSecure = strtolower(trim((string) ($_POST['smtp_secure'] ?? 'tls')));
        $smtpUsername = trim((string) ($_POST['smtp_username'] ?? ''));
        $smtpPassword = trim((string) ($_POST['smtp_password'] ?? ''));
        $smtpTimeout = (int) ($_POST['smtp_timeout_seconds'] ?? 10);
        $mailFromAddress = trim((string) ($_POST['mail_from_address'] ?? ''));
        $mailFromName = trim((string) ($_POST['mail_from_name'] ?? ''));
        $hrAlertsCronRunsPerDay = (int) ($_POST['hr_alerts_inline_cron_runs_per_day'] ?? 1440);
        $companyDailyObjective = trim((string) ($_POST['company_daily_objective'] ?? '08:15'));

        if (!in_array($smtpSecure, ['', 'tls', 'ssl'], true)) {
            $smtpSecure = 'tls';
        }
        if ($smtpPort < 1 || $smtpPort > 65535) {
            $smtpPort = 587;
        }
        if ($smtpTimeout < 3 || $smtpTimeout > 120) {
            $smtpTimeout = 10;
        }
        if ($hrAlertsCronRunsPerDay < 1) {
            $hrAlertsCronRunsPerDay = 1;
        } elseif ($hrAlertsCronRunsPerDay > 1440) {
            $hrAlertsCronRunsPerDay = 1440;
        }

        if (preg_match('/^(\d{1,2}):(\d{2})$/', $companyDailyObjective, $dailyObjectiveMatches) !== 1 || (int) $dailyObjectiveMatches[1] > 23 || (int) $dailyObjectiveMatches[2] > 59) {
            $flashError = 'Indique um objetivo diário válido no formato HH:MM.';
        } elseif ($companyEmail !== '' && filter_var($companyEmail, FILTER_VALIDATE_EMAIL) === false) {
            $flashError = 'Indique um email válido para a empresa.';
        } elseif ($smtpHost !== '' && $smtpUsername === '') {
            $flashError = 'Preencha o utilizador SMTP quando definir um servidor SMTP.';
        } elseif ($smtpHost !== '' && $smtpPassword === '') {
            $flashError = 'Preencha a password SMTP quando definir um servidor SMTP.';
        } elseif ($mailFromAddress !== '' && filter_var($mailFromAddress, FILTER_VALIDATE_EMAIL) === false) {
            $flashError = 'Indique um email válido para o remetente.';
        } else {
            set_app_setting($pdo, 'company_name', $companyName);
            set_app_setting($pdo, 'company_address', $companyAddress);
            set_app_setting($pdo, 'company_email', $companyEmail);
            set_app_setting($pdo, 'company_phone', $companyPhone);
            set_app_setting($pdo, 'smtp_host', $smtpHost);
            set_app_setting($pdo, 'smtp_port', (string) $smtpPort);
            set_app_setting($pdo, 'smtp_secure', $smtpSecure);
            set_app_setting($pdo, 'smtp_username', $smtpUsername);
            set_app_setting($pdo, 'smtp_password', $smtpPassword);
            set_app_setting($pdo, 'smtp_timeout_seconds', (string) $smtpTimeout);
            set_app_setting($pdo, 'mail_from_address', $mailFromAddress);
            set_app_setting($pdo, 'mail_from_name', $mailFromName);
            set_app_setting($pdo, 'hr_alerts_inline_cron_runs_per_day', (string) $hrAlertsCronRunsPerDay);
            set_app_setting($pdo, 'company_daily_objective', sprintf('%02d:%02d', (int) $dailyObjectiveMatches[1], (int) $dailyObjectiveMatches[2]));

            $savedLogos = 0;
            $lightPath = save_brand_logo($_FILES['logo_navbar_light'] ?? [], 'navbar_light');
            if ($lightPath) {
                set_app_setting($pdo, 'logo_navbar_light', $lightPath);
                $savedLogos++;
            }

            $darkPath = save_brand_logo($_FILES['logo_report_dark'] ?? [], 'report_dark');
            if ($darkPath) {
                set_app_setting($pdo, 'logo_report_dark', $darkPath);
                $savedLogos++;
            }

            $flashSuccess = $savedLogos > 0
                ? 'Dados da empresa e logotipos atualizados com sucesso.'
                : 'Dados da empresa atualizados com sucesso.';
        }
    } elseif (($_POST['action'] ?? '') === 'upload_hr_greeting_image') {
        $type = (string) ($_POST['greeting_type'] ?? '');
        $title = trim((string) ($_POST['greeting_title'] ?? ''));
        if (!in_array($type, ['birthday', 'work_anniversary'], true)) {
            $flashError = 'Tipo de imagem inválido.';
        } elseif ($title === '') {
            $flashError = 'Indique um título para a imagem.';
        } else {
            $countStmt = $pdo->prepare('SELECT COUNT(*) FROM hr_greeting_images WHERE greeting_type = ?');
            $countStmt->execute([$type]);
            $limit = $type === 'work_anniversary' ? 30 : 1;
            if ((int) $countStmt->fetchColumn() >= $limit) {
                $flashError = $type === 'work_anniversary' ? 'Só pode configurar até 30 imagens de aniversário de empresa.' : 'Só pode configurar uma imagem de parabéns.';
            } else {
                $upload = save_hr_greeting_image_upload($_FILES['greeting_image'] ?? [], $type);
                if ($upload === null) {
                    $flashError = 'Envie uma imagem válida (PNG, JPG ou WebP).';
                } else {
                    $sortOrderStmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM hr_greeting_images WHERE greeting_type = ?');
                    $sortOrderStmt->execute([$type]);
                    $stmt = $pdo->prepare('INSERT INTO hr_greeting_images(greeting_type, title, file_path, original_name, mime_type, sort_order, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
                    $stmt->execute([$type, $title, $upload['path'], $upload['name'], $upload['mime'], (int) $sortOrderStmt->fetchColumn(), $userId]);
                    $flashSuccess = 'Imagem guardada com sucesso.';
                }
            }
        }
    } elseif (($_POST['action'] ?? '') === 'delete_hr_greeting_image') {
        $imageId = (int) ($_POST['image_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT file_path FROM hr_greeting_images WHERE id = ? LIMIT 1');
        $stmt->execute([$imageId]);
        $path = (string) ($stmt->fetchColumn() ?: '');
        $pdo->prepare('DELETE FROM hr_greeting_images WHERE id = ?')->execute([$imageId]);
        if ($path !== '' && str_starts_with($path, 'assets/uploads/hr_greetings/')) {
            @unlink(__DIR__ . '/' . $path);
        }
        $flashSuccess = 'Imagem eliminada.';
    } elseif (($_POST['action'] ?? '') === 'reset_hr_operational_data') {
        $confirmation = trim((string) ($_POST['reset_confirmation'] ?? ''));
        if (mb_strtoupper($confirmation, 'UTF-8') !== 'RESET') {
            $flashError = 'Para confirmar a limpeza dos dados, escreva RESET no campo de confirmação.';
        } else {
            $tablesToReset = [
                'shopfloor_absence_time_allocations',
                'shopfloor_justifications',
                'shopfloor_absence_requests',
                'shopfloor_vacation_requests',
                'shopfloor_time_entries',
                'shopfloor_hour_banks',
                'shopfloor_bh_overrides',
                'shopfloor_bh_override_logs',
                'hr_hour_bank_logs',
                'hr_vacation_events',
                'hr_vacation_balances',
                'hr_calendar_events',
            ];

            try {
                $pdo->beginTransaction();
                $deletedRows = 0;
                foreach ($tablesToReset as $tableName) {
                    $deletedRows += (int) $pdo->exec('DELETE FROM ' . $tableName);
                }
                $pdo->commit();
                log_app_event($pdo, $userId, 'company_profile.reset_hr_operational_data', 'Limpeza total de picagens e pedidos de ausências/férias.', ['deleted_rows' => $deletedRows]);
                $flashSuccess = $deletedRows > 0
                    ? 'Limpeza concluída: foram removidos ' . $deletedRows . ' registos de picagens, ausências e férias.'
                    : 'Não existiam registos de picagens, ausências ou férias para limpar.';
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $flashError = 'Não foi possível concluir a limpeza dos dados operacionais.';
            }
        }
    }
}

$companyName = (string) app_setting($pdo, 'company_name', '');
$companyAddress = (string) app_setting($pdo, 'company_address', '');
$companyEmail = (string) app_setting($pdo, 'company_email', '');
$companyPhone = (string) app_setting($pdo, 'company_phone', '');
$smtpHost = (string) app_setting($pdo, 'smtp_host', '');
$smtpPort = (string) app_setting($pdo, 'smtp_port', '587');
$smtpSecure = (string) app_setting($pdo, 'smtp_secure', 'tls');
$smtpUsername = (string) app_setting($pdo, 'smtp_username', '');
$smtpPassword = (string) app_setting($pdo, 'smtp_password', '');
$smtpTimeout = (string) app_setting($pdo, 'smtp_timeout_seconds', '10');
$mailFromAddress = (string) app_setting($pdo, 'mail_from_address', 'noreply@calcadacorp.ch');
$mailFromName = (string) app_setting($pdo, 'mail_from_name', 'GesTisser');
$hrAlertsCronRunsPerDay = (string) app_setting($pdo, 'hr_alerts_inline_cron_runs_per_day', '1440');
$companyDailyObjective = format_minutes_hhmm(company_daily_objective_minutes($pdo));
$navbarLogo = app_setting($pdo, 'logo_navbar_light');
$reportLogo = app_setting($pdo, 'logo_report_dark');
$greetingStmt = $pdo->query('SELECT id, greeting_type, title, file_path, sort_order, is_active, created_at FROM hr_greeting_images ORDER BY greeting_type ASC, sort_order ASC, id ASC');
foreach ($greetingStmt->fetchAll(PDO::FETCH_ASSOC) as $greetingImage) {
    $greetingImages[(string) $greetingImage['greeting_type']][] = $greetingImage;
}
} catch (Throwable $exception) {
    $flashError = 'Não foi possível carregar as definições da página. Verifique a configuração e tente novamente.';
    if (function_exists('taskforce_log_bootstrap_error')) {
        taskforce_log_bootstrap_error('[GesTisser][company_profile] ' . $exception->getMessage());
    }
}

$pageTitle = 'Empresa e Branding';
require __DIR__ . '/partials/header.php';
?>
<h1 class="h3 mb-3">Empresa e Branding</h1>
<p class="text-muted">Configure os dados corporativos para reutilizar nos relatórios e e-mails.</p>

<?php if ($flashSuccess): ?><div class="alert alert-success"><?= h($flashSuccess) ?></div><?php endif; ?>
<?php if ($flashError): ?><div class="alert alert-danger"><?= h($flashError) ?></div><?php endif; ?>

<form method="post" enctype="multipart/form-data" class="card shadow-sm soft-card">
    <input type="hidden" name="action" value="save_company_profile">
    <div class="card-body p-4">
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Nome da empresa</label>
                <input class="form-control" name="company_name" value="<?= h($companyName) ?>" <?= !$isAdmin ? 'readonly' : '' ?>>
            </div>
            <div class="col-md-6">
                <label class="form-label">Email</label>
                <input class="form-control" type="email" name="company_email" value="<?= h($companyEmail) ?>" <?= !$isAdmin ? 'readonly' : '' ?>>
            </div>
            <div class="col-md-6">
                <label class="form-label">Telefone</label>
                <input class="form-control" name="company_phone" value="<?= h($companyPhone) ?>" <?= !$isAdmin ? 'readonly' : '' ?>>
            </div>
            <div class="col-md-6">
                <label class="form-label">Morada</label>
                <input class="form-control" name="company_address" value="<?= h($companyAddress) ?>" <?= !$isAdmin ? 'readonly' : '' ?>>
            </div>
            <div class="col-12">
                <hr>
                <label class="form-label mb-0">Configuração de envio de email (SMTP)</label>
                <p class="small text-muted mb-2">Preencha estes campos para envio autenticado de alertas e relatórios quando o servidor não usa <code>mail()</code>.</p>
            </div>
            <div class="col-md-4">
                <label class="form-label">Servidor SMTP</label>
                <input class="form-control" name="smtp_host" placeholder="smtp.seudominio.com" value="<?= h($smtpHost) ?>" <?= !$isAdmin ? 'readonly' : '' ?>>
            </div>
            <div class="col-md-2">
                <label class="form-label">Porta SMTP</label>
                <input class="form-control" type="number" min="1" max="65535" name="smtp_port" value="<?= h((string) $smtpPort) ?>" <?= !$isAdmin ? 'readonly' : '' ?>>
            </div>
            <div class="col-md-2">
                <label class="form-label">Segurança</label>
                <select class="form-select" name="smtp_secure" <?= !$isAdmin ? 'disabled' : '' ?>>
                    <option value="" <?= $smtpSecure === '' ? 'selected' : '' ?>>Sem TLS</option>
                    <option value="tls" <?= $smtpSecure === 'tls' ? 'selected' : '' ?>>STARTTLS</option>
                    <option value="ssl" <?= $smtpSecure === 'ssl' ? 'selected' : '' ?>>SSL</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Utilizador SMTP</label>
                <input class="form-control" name="smtp_username" value="<?= h($smtpUsername) ?>" <?= !$isAdmin ? 'readonly' : '' ?>>
            </div>
            <div class="col-md-4">
                <label class="form-label">Password SMTP</label>
                <input class="form-control" type="password" name="smtp_password" value="<?= h($smtpPassword) ?>" autocomplete="new-password" <?= !$isAdmin ? 'readonly' : '' ?>>
            </div>
            <div class="col-md-2">
                <label class="form-label">Timeout (s)</label>
                <input class="form-control" type="number" min="3" max="120" name="smtp_timeout_seconds" value="<?= h((string) $smtpTimeout) ?>" <?= !$isAdmin ? 'readonly' : '' ?>>
            </div>
            <div class="col-md-3">
                <label class="form-label">Email remetente</label>
                <input class="form-control" type="email" name="mail_from_address" value="<?= h($mailFromAddress) ?>" <?= !$isAdmin ? 'readonly' : '' ?>>
            </div>
            <div class="col-md-3">
                <label class="form-label">Nome remetente</label>
                <input class="form-control" name="mail_from_name" value="<?= h($mailFromName) ?>" <?= !$isAdmin ? 'readonly' : '' ?>>
            </div>
            <div class="col-md-3">
                <label class="form-label">Verificações alertas RH por dia</label>
                <input class="form-control" type="number" min="1" max="1440" name="hr_alerts_inline_cron_runs_per_day" value="<?= h((string) $hrAlertsCronRunsPerDay) ?>" <?= !$isAdmin ? 'readonly' : '' ?>>
                <p class="small text-muted mb-0 mt-1">Define quantas vezes por dia o sistema verifica se existem envios de alertas RH por executar (1 a 1440).</p>
            </div>
            <div class="col-md-3">
                <label class="form-label">Objetivo diário</label>
                <input class="form-control" type="time" name="company_daily_objective" value="<?= h($companyDailyObjective) ?>" <?= !$isAdmin ? 'readonly' : '' ?>>
                <p class="small text-muted mb-0 mt-1">Define o objetivo diário usado no cálculo do banco de horas.</p>
            </div>

            <div class="col-md-6">
                <label class="form-label mb-0">Logo claro (navbar)</label>
                <input class="form-control form-control-sm mb-2" type="file" name="logo_navbar_light" accept="image/png,image/jpeg,image/svg+xml,image/webp" <?= !$isAdmin ? 'disabled' : '' ?>>
                <?php if ($navbarLogo): ?><img src="<?= h($navbarLogo) ?>" alt="Logo navbar" class="img-fluid border rounded p-2 mb-2"><?php endif; ?>
            </div>
            <div class="col-md-6">
                <label class="form-label mb-0">Logo escuro (relatórios)</label>
                <input class="form-control form-control-sm mb-2" type="file" name="logo_report_dark" accept="image/png,image/jpeg,image/svg+xml,image/webp" <?= !$isAdmin ? 'disabled' : '' ?>>
                <?php if ($reportLogo): ?><img src="<?= h($reportLogo) ?>" alt="Logo relatório" class="img-fluid border rounded p-2 mb-2"><?php endif; ?>
            </div>
        </div>

        <?php if ($isAdmin): ?>
            <button class="btn btn-primary mt-3">Guardar dados da empresa</button>
        <?php endif; ?>
    </div>
</form>


<?php if ($isAdmin): ?>
<div class="card shadow-sm soft-card mt-3">
    <div class="card-body p-4">
        <h2 class="h5">Imagens de parabéns por email</h2>
        <p class="small text-muted">Estas imagens são anexadas automaticamente aos emails do cron RH quando a data de nascimento ou admissão coincide com o dia.</p>
        <?php foreach ([['birthday', 'Aniversário do colaborador', 1], ['work_anniversary', 'Aniversário de empresa', 30]] as $greetingConfig): ?>
            <?php list($greetingType, $greetingLabel, $greetingLimit) = $greetingConfig; ?>
            <div class="border rounded p-3 mb-3">
                <h3 class="h6 mb-2"><?= h($greetingLabel) ?> <span class="text-muted small">(<?= count($greetingImages[$greetingType] ?? []) ?>/<?= (int) $greetingLimit ?>)</span></h3>
                <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end mb-3">
                    <input type="hidden" name="action" value="upload_hr_greeting_image">
                    <input type="hidden" name="greeting_type" value="<?= h($greetingType) ?>">
                    <div class="col-md-4"><label class="form-label">Título</label><input class="form-control" name="greeting_title" required></div>
                    <div class="col-md-5"><label class="form-label">Imagem</label><input class="form-control" type="file" name="greeting_image" accept="image/png,image/jpeg,image/webp" required></div>
                    <div class="col-md-3"><button class="btn btn-outline-primary w-100" <?= count($greetingImages[$greetingType] ?? []) >= $greetingLimit ? 'disabled' : '' ?>>Adicionar imagem</button></div>
                </form>
                <div class="row g-2">
                    <?php foreach (($greetingImages[$greetingType] ?? []) as $image): ?>
                        <div class="col-md-3"><div class="border rounded p-2 h-100"><img src="<?= h((string) $image['file_path']) ?>" alt="<?= h((string) $image['title']) ?>" class="img-fluid rounded mb-2"><div class="small fw-semibold"><?= h((string) $image['title']) ?></div><form method="post" class="mt-2"><input type="hidden" name="action" value="delete_hr_greeting_image"><input type="hidden" name="image_id" value="<?= (int) $image['id'] ?>"><button class="btn btn-sm btn-outline-danger" onclick="return confirm('Eliminar esta imagem?');">Eliminar</button></form></div></div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($isAdmin): ?>
<form method="post" class="card shadow-sm border-danger-subtle mt-3">
    <input type="hidden" name="action" value="reset_hr_operational_data">
    <div class="card-body">
        <h2 class="h5 text-danger">Zona de limpeza para nova empresa</h2>
        <p class="small text-muted mb-2">
            Esta ação elimina todos os registos operacionais de picagens e pedidos de ausências/férias para preparar uma implementação nova.
        </p>
        <ul class="small text-muted mb-3">
            <li>Picagens (Shopfloor)</li>
            <li>Pedidos e justificações de ausências</li>
            <li>Pedidos e eventos de férias</li>
            <li>Saldos e movimentos de banco de horas</li>
        </ul>
        <div class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label">Confirmação</label>
                <input class="form-control" name="reset_confirmation" placeholder="Escreva RESET para confirmar" required>
            </div>
            <div class="col-md-8">
                <button class="btn btn-outline-danger" onclick="return confirm('Confirma a eliminação total de picagens e pedidos de ausências/férias? Esta ação é irreversível.');">
                    Eliminar dados de picagens e ausências/férias
                </button>
            </div>
        </div>
    </div>
</form>
<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
