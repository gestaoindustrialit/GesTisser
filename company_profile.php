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
$ticketStatuses = [];
$recurrenceCatalog = [];
$pendingDepartmentCatalog = [];
$greetingImages = ['birthday' => [], 'work_anniversary' => []];

function save_hr_greeting_image_upload(array $file, string $type): ?array
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

        $statusValues = $_POST['ticket_status_value'] ?? [];
        $statusLabels = $_POST['ticket_status_label'] ?? [];
        $statusCompleted = $_POST['ticket_status_completed'] ?? [];
        $statusSortOrders = $_POST['ticket_status_sort_order'] ?? [];
        $statusColors = $_POST['ticket_status_color'] ?? [];
        $ticketStatuses = [];

        $recurrenceValues = $_POST['recurrence_value'] ?? [];
        $recurrenceLabels = $_POST['recurrence_label'] ?? [];
        $recurrenceEnabled = $_POST['recurrence_enabled'] ?? [];
        $recurrenceCatalog = [];

        $pendingDepartmentValues = $_POST['pending_department_value'] ?? [];
        $pendingDepartmentEnabled = $_POST['pending_department_enabled'] ?? [];
        $pendingDepartmentCatalog = [];

        foreach ($statusValues as $index => $rawValue) {
            $value = strtolower(trim((string) $rawValue));
            $label = trim((string) ($statusLabels[$index] ?? ''));
            $sortOrder = (int) ($statusSortOrders[$index] ?? 0);
            $color = strtoupper(trim((string) ($statusColors[$index] ?? '')));

            if ($value === '' && $label === '') {
                continue;
            }

            $value = preg_replace('/[^a-z0-9_\-]/', '_', $value) ?: '';
            if ($value === '' || $label === '') {
                continue;
            }

            if (!preg_match('/^#[0-9A-F]{6}$/', $color)) {
                $color = (isset($statusCompleted[$index]) && $statusCompleted[$index] === '1') ? '#22C55E' : '#FACC15';
            }

            if (isset($ticketStatuses[$value])) {
                continue;
            }

            $ticketStatuses[$value] = [
                'value' => $value,
                'label' => $label,
                'is_completed' => isset($statusCompleted[$index]) && $statusCompleted[$index] === '1',
                'sort_order' => $sortOrder,
                'color' => $color,
            ];
        }

        $defaultRecurrences = default_recurring_task_recurrence_options();
        $allowedRecurrences = [];
        foreach ($defaultRecurrences as $defaultRecurrence) {
            $allowedRecurrences[(string) $defaultRecurrence['value']] = (string) $defaultRecurrence['label'];
        }

        $defaultPendingDepartments = default_pending_ticket_department_options($pdo);
        $allowedPendingDepartments = [];
        foreach ($defaultPendingDepartments as $defaultDepartment) {
            $allowedPendingDepartments[(string) $defaultDepartment['value']] = (string) $defaultDepartment['label'];
        }

        foreach ($pendingDepartmentValues as $index => $rawValue) {
            $value = strtolower(trim((string) $rawValue));
            if (!isset($allowedPendingDepartments[$value]) || isset($pendingDepartmentCatalog[$value])) {
                continue;
            }

            $pendingDepartmentCatalog[$value] = [
                'value' => $value,
                'label' => $allowedPendingDepartments[$value],
                'enabled' => isset($pendingDepartmentEnabled[$index]) && $pendingDepartmentEnabled[$index] === '1',
            ];
        }

        foreach ($defaultPendingDepartments as $defaultDepartment) {
            $value = (string) $defaultDepartment['value'];
            if (!isset($pendingDepartmentCatalog[$value])) {
                $pendingDepartmentCatalog[$value] = [
                    'value' => $value,
                    'label' => (string) $defaultDepartment['label'],
                    'enabled' => !empty($defaultDepartment['enabled']),
                ];
            }
        }

        foreach ($recurrenceValues as $index => $rawValue) {
            $value = strtolower(trim((string) $rawValue));
            if (!isset($allowedRecurrences[$value]) || isset($recurrenceCatalog[$value])) {
                continue;
            }

            $label = trim((string) ($recurrenceLabels[$index] ?? ''));
            if ($label === '') {
                $label = $allowedRecurrences[$value];
            }

            $recurrenceCatalog[$value] = [
                'value' => $value,
                'label' => $label,
                'enabled' => isset($recurrenceEnabled[$index]) && $recurrenceEnabled[$index] === '1',
            ];
        }

        foreach ($defaultRecurrences as $defaultRecurrence) {
            $value = (string) $defaultRecurrence['value'];
            if (!isset($recurrenceCatalog[$value])) {
                $recurrenceCatalog[$value] = [
                    'value' => $value,
                    'label' => (string) $defaultRecurrence['label'],
                    'enabled' => !empty($defaultRecurrence['enabled']),
                ];
            }
        }

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
        } elseif (count($ticketStatuses) === 0) {
            $flashError = 'Defina pelo menos um estado para os tickets.';
        } elseif (!array_filter($ticketStatuses, static function (array $status): bool { return empty($status['is_completed']); })) {
            $flashError = 'Defina pelo menos um estado não concluído para os tickets.';
        } elseif (!array_filter($recurrenceCatalog, static function (array $entry): bool { return !empty($entry['enabled']); })) {
            $flashError = 'Ative pelo menos um tipo de recorrência para tarefas recorrentes.';
        } elseif (!array_filter($pendingDepartmentCatalog, static function (array $entry): bool { return !empty($entry['enabled']); })) {
            $flashError = 'Ative pelo menos um departamento para pendentes no dashboard.';
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
            set_app_setting($pdo, 'ticket_statuses_json', json_encode(array_values($ticketStatuses), JSON_UNESCAPED_UNICODE));
            set_app_setting($pdo, 'recurring_task_recurrences_json', json_encode(array_values($recurrenceCatalog), JSON_UNESCAPED_UNICODE));
            set_app_setting($pdo, 'pending_ticket_departments_json', json_encode(array_values($pendingDepartmentCatalog), JSON_UNESCAPED_UNICODE));

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
$ticketStatuses = ticket_statuses($pdo);
$recurrenceCatalog = recurring_task_recurrence_catalog($pdo);
$pendingDepartmentCatalog = function_exists('pending_ticket_department_catalog')
    ? pending_ticket_department_catalog($pdo)
    : default_pending_ticket_department_options($pdo);
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
            <div class="col-12">
                <hr>
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <label class="form-label mb-0">Estados dos tickets</label>
                    <?php if ($isAdmin): ?><button type="button" class="btn btn-sm btn-outline-secondary" id="add-ticket-status">Adicionar estado</button><?php endif; ?>
                </div>
                <p class="small text-muted mb-2">Defina os estados disponíveis no ticketing, a ordem de apresentação e a cor hexadecimal do badge de alerta.</p>
                <div id="ticket-status-list" class="vstack gap-2">
                    <?php foreach ($ticketStatuses as $index => $status): ?>
                        <div class="row g-2 align-items-center ticket-status-row border rounded p-2">
                            <div class="col-md-2"><input class="form-control form-control-sm" name="ticket_status_value[]" value="<?= h($status['value']) ?>" placeholder="valor_tecnico" <?= !$isAdmin ? 'readonly' : '' ?>></div>
                            <div class="col-md-3"><input class="form-control form-control-sm" name="ticket_status_label[]" value="<?= h($status['label']) ?>" placeholder="Etiqueta" <?= !$isAdmin ? 'readonly' : '' ?>></div>
                            <div class="col-md-2"><input class="form-control form-control-sm ticket-status-sort-order" type="number" name="ticket_status_sort_order[]" value="<?= (int) ($status['sort_order'] ?? (($index + 1) * 10)) ?>" placeholder="Ordem" <?= !$isAdmin ? 'readonly' : '' ?>></div>
                            <div class="col-md-2">
                                <div class="d-flex align-items-center gap-2">
                                    <input class="form-control form-control-color form-control-sm ticket-status-color-picker" type="color" value="<?= h($status['color'] ?? (!empty($status['is_completed']) ? '#22C55E' : '#FACC15')) ?>" title="Cor do badge" <?= !$isAdmin ? 'disabled' : '' ?>>
                                    <input class="form-control form-control-sm text-uppercase ticket-status-color-hex" name="ticket_status_color[]" value="<?= h($status['color'] ?? (!empty($status['is_completed']) ? '#22C55E' : '#FACC15')) ?>" pattern="^#[0-9A-Fa-f]{6}$" placeholder="#RRGGBB" <?= !$isAdmin ? 'readonly' : '' ?>>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <div class="form-check">
                                    <input class="form-check-input ticket-status-completed" type="checkbox" name="ticket_status_completed[<?= (int) $index ?>]" value="1" <?= !empty($status['is_completed']) ? 'checked' : '' ?> <?= !$isAdmin ? 'disabled' : '' ?>>
                                    <label class="form-check-label small">Concluído</label>
                                </div>
                            </div>
                            <div class="col-md-1">
                                <?php if ($isAdmin): ?>
                                    <div class="d-flex gap-1 justify-content-end">
                                        <button type="button" class="btn btn-sm btn-outline-secondary move-ticket-status-up" title="Subir">↑</button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary move-ticket-status-down" title="Descer">↓</button>
                                        <button type="button" class="btn btn-sm btn-outline-danger remove-ticket-status">×</button>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="col-12">
                <hr>
                <label class="form-label mb-0">Departamentos pendentes no dashboard</label>
                <p class="small text-muted mb-2">Escolha os departamentos que devem aparecer automaticamente no bloco de pendentes por equipa técnica.</p>
                <div id="pending-department-list" class="vstack gap-2 mb-2">
                    <?php foreach ($pendingDepartmentCatalog as $index => $department): ?>
                        <div class="row g-2 align-items-center">
                            <div class="col-md-4"><input class="form-control form-control-sm" name="pending_department_value[]" value="<?= h($department['value']) ?>" readonly></div>
                            <div class="col-md-5"><input class="form-control form-control-sm" value="<?= h($department['label']) ?>" readonly></div>
                            <div class="col-md-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="pending_department_enabled[<?= (int) $index ?>]" value="1" <?= !empty($department['enabled']) ? 'checked' : '' ?> <?= !$isAdmin ? 'disabled' : '' ?>>
                                    <label class="form-check-label small">Ativo</label>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="col-12">
                <hr>
                <label class="form-label mb-0">Recorrências de tarefas</label>
                <p class="small text-muted mb-2">Personalize os nomes e ative/desative tipos de recorrência disponíveis ao criar tarefas recorrentes.</p>
                <div id="recurrence-list" class="vstack gap-2">
                    <?php foreach ($recurrenceCatalog as $index => $recurrence): ?>
                        <div class="row g-2 align-items-center recurrence-row">
                            <div class="col-md-3"><input class="form-control form-control-sm" name="recurrence_value[]" value="<?= h($recurrence['value']) ?>" readonly></div>
                            <div class="col-md-6"><input class="form-control form-control-sm" name="recurrence_label[]" value="<?= h($recurrence['label']) ?>" placeholder="Etiqueta" <?= !$isAdmin ? 'readonly' : '' ?>></div>
                            <div class="col-md-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="recurrence_enabled[<?= (int) $index ?>]" value="1" <?= !empty($recurrence['enabled']) ? 'checked' : '' ?> <?= !$isAdmin ? 'disabled' : '' ?>>
                                    <label class="form-check-label small">Ativo</label>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
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
            <?php [$greetingType, $greetingLabel, $greetingLimit] = $greetingConfig; ?>
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

<?php if ($isAdmin): ?>
<script>
(function () {
    const list = document.getElementById('ticket-status-list');
    const addButton = document.getElementById('add-ticket-status');
    if (!list || !addButton) {
        return;
    }


    const normalizeRows = () => {
        list.querySelectorAll('.ticket-status-row').forEach((row, index) => {
            const sortOrderInput = row.querySelector('.ticket-status-sort-order');
            if (sortOrderInput) {
                sortOrderInput.value = String((index + 1) * 10);
            }

            const completedInput = row.querySelector('.ticket-status-completed');
            if (completedInput) {
                completedInput.name = `ticket_status_completed[${index}]`;
            }

            const upBtn = row.querySelector('.move-ticket-status-up');
            const downBtn = row.querySelector('.move-ticket-status-down');
            if (upBtn) {
                upBtn.disabled = index === 0;
            }
            if (downBtn) {
                downBtn.disabled = index === list.querySelectorAll('.ticket-status-row').length - 1;
            }
        });
    };

    const bindColorSync = (row) => {
        const picker = row.querySelector('.ticket-status-color-picker');
        const hex = row.querySelector('.ticket-status-color-hex');
        if (!picker || !hex) {
            return;
        }

        picker.addEventListener('input', () => {
            hex.value = picker.value.toUpperCase();
        });

        hex.addEventListener('input', () => {
            const normalized = hex.value.trim().toUpperCase();
            if (/^#[0-9A-F]{6}$/.test(normalized)) {
                picker.value = normalized;
            }
        });
    };

    const bindMove = (button, direction) => {
        button.addEventListener('click', () => {
            const row = button.closest('.ticket-status-row');
            if (!row) {
                return;
            }

            if (direction === 'up') {
                const previous = row.previousElementSibling;
                if (previous) {
                    list.insertBefore(row, previous);
                }
            } else {
                const next = row.nextElementSibling;
                if (next) {
                    list.insertBefore(next, row);
                }
            }

            normalizeRows();
        });
    };

    const bindRemove = (button) => {
        button.addEventListener('click', () => {
            const row = button.closest('.ticket-status-row');
            if (row) {
                row.remove();
                normalizeRows();
            }
        });
    };

    const bindRowControls = (row) => {
        bindColorSync(row);

        const remove = row.querySelector('.remove-ticket-status');
        if (remove) {
            bindRemove(remove);
        }

        const up = row.querySelector('.move-ticket-status-up');
        if (up) {
            bindMove(up, 'up');
        }

        const down = row.querySelector('.move-ticket-status-down');
        if (down) {
            bindMove(down, 'down');
        }
    };

    list.querySelectorAll('.ticket-status-row').forEach(bindRowControls);
    normalizeRows();

    addButton.addEventListener('click', () => {
        const index = list.querySelectorAll('.ticket-status-row').length;
        const wrapper = document.createElement('div');
        wrapper.className = 'row g-2 align-items-center ticket-status-row border rounded p-2';
        wrapper.innerHTML = `
            <div class="col-md-2"><input class="form-control form-control-sm" name="ticket_status_value[]" placeholder="valor_tecnico"></div>
            <div class="col-md-3"><input class="form-control form-control-sm" name="ticket_status_label[]" placeholder="Etiqueta"></div>
            <div class="col-md-2"><input class="form-control form-control-sm ticket-status-sort-order" type="number" name="ticket_status_sort_order[]" value="${(index + 1) * 10}" placeholder="Ordem"></div>
            <div class="col-md-2">
                <div class="d-flex align-items-center gap-2">
                    <input class="form-control form-control-color form-control-sm ticket-status-color-picker" type="color" value="#FACC15" title="Cor do badge">
                    <input class="form-control form-control-sm text-uppercase ticket-status-color-hex" name="ticket_status_color[]" value="#FACC15" pattern="^#[0-9A-Fa-f]{6}$" placeholder="#RRGGBB">
                </div>
            </div>
            <div class="col-md-2"><div class="form-check"><input class="form-check-input ticket-status-completed" type="checkbox" name="ticket_status_completed[${index}]" value="1"><label class="form-check-label small">Concluído</label></div></div>
            <div class="col-md-1">
                <div class="d-flex gap-1 justify-content-end">
                    <button type="button" class="btn btn-sm btn-outline-secondary move-ticket-status-up" title="Subir">↑</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary move-ticket-status-down" title="Descer">↓</button>
                    <button type="button" class="btn btn-sm btn-outline-danger remove-ticket-status">×</button>
                </div>
            </div>
        `;
        list.appendChild(wrapper);
        bindRowControls(wrapper);
        normalizeRows();
    });

    const form = list.closest('form');
    if (form) {
        form.addEventListener('submit', () => {
            normalizeRows();
        });
    }
})();
</script>
<?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
