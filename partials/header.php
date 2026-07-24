<?php
$bootstrapPath = dirname(__DIR__) . '/bootstrap/app.php';
if (is_file($bootstrapPath)) {
    require_once $bootstrapPath;
} else {
    $helpersPath = dirname(__DIR__) . '/helpers.php';
    if (!is_file($helpersPath)) {
        $helpersPath = __DIR__ . '/helpers.php';
    }
    require_once $helpersPath;
}
$user = current_user($pdo);
$navbarLogo = app_setting($pdo, 'logo_navbar_light');
$showHrMenu = $user && ((int) ($user['is_admin'] ?? 0) === 1 || (string) ($user['access_profile'] ?? '') === 'RH');
$isPinOnlyUser = $user && (int) ($user['pin_only_login'] ?? 0) === 1;

if ($user && !isset($navbarClockControl)) {
    $todayEntriesStmt = $pdo->prepare('SELECT entry_type, occurred_at AS occurred_at_local FROM shopfloor_time_entries WHERE user_id = ? AND date(occurred_at) = date("now") ORDER BY occurred_at DESC');
    $todayEntriesStmt->execute([(int) $user['id']]);
    $todayEntries = $todayEntriesStmt->fetchAll(PDO::FETCH_ASSOC);

    $latestTodayEntry = $todayEntries[0] ?? null;
    $nextEntryType = $latestTodayEntry && (($latestTodayEntry['entry_type'] ?? '') === 'entrada') ? 'saida' : 'entrada';
    $clockButtonLabel = $nextEntryType === 'entrada' ? 'Ponto de entrada' : 'Ponto de saída';
    $clockButtonClass = $nextEntryType === 'entrada' ? 'btn-primary' : 'btn-outline-light';
    $latestEntryTimeLabel = null;

    if ($latestTodayEntry && !empty($latestTodayEntry['occurred_at_local'])) {
        $latestTimestamp = strtotime((string) $latestTodayEntry['occurred_at_local']);
        if ($latestTimestamp !== false) {
            $latestEntryTimeLabel = sprintf(
                '%s às %s',
                (($latestTodayEntry['entry_type'] ?? '') === 'entrada') ? 'Entrada' : 'Saída',
                date('H:i', $latestTimestamp)
            );
        }
    }

    $navbarClockControl = [
        'form_action' => 'shopfloor.php',
        'entry_type' => $nextEntryType,
        'button_label' => $clockButtonLabel,
        'button_class' => $clockButtonClass,
        'latest_time_label' => $latestEntryTimeLabel,
    ];

    $activeBreakStmt = $pdo->prepare('SELECT b.id, b.break_reason_id, b.started_at, b.comment, r.code, r.label, r.break_type, r.requires_comment, CAST((julianday(CURRENT_TIMESTAMP) - julianday(b.started_at)) * 86400 AS INTEGER) AS elapsed_seconds FROM shopfloor_break_entries b INNER JOIN shopfloor_break_reasons r ON r.id = b.break_reason_id WHERE b.user_id = ? AND b.ended_at IS NULL ORDER BY b.started_at DESC LIMIT 1');
    $activeBreakStmt->execute([(int) $user['id']]);
    $activeBreak = $activeBreakStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $breakReasonOptionsStmt = $pdo->query('SELECT id, code, label, break_type, requires_comment FROM shopfloor_break_reasons WHERE is_active = 1 ORDER BY code COLLATE NOCASE ASC');
    $breakReasonOptions = $breakReasonOptionsStmt ? $breakReasonOptionsStmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $navbarBreakControl = [
        'form_action' => 'shopfloor.php',
        'active_break' => $activeBreak,
        'reason_options' => $breakReasonOptions,
    ];
}

$currentFile = basename((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH));
if ($currentFile === '') {
    $currentFile = basename((string) ($_SERVER['PHP_SELF'] ?? ''));
}
$currentErpPage = trim((string) ($_GET['page'] ?? 'overview'));
$hrFiles = [
    'hr.php', 'users.php', 'hr_departments.php', 'hr_schedules.php', 'hr_calendar.php',
    'hr_bank.php', 'hr_absences.php', 'hr_vacations.php', 'hr_alerts.php',
    'hr_evaluations.php', 'hr_evaluation_rules.php', 'hr_evaluation_history.php',
    'resultados.php', 'shopfloor_absence_reasons.php', 'shopfloor_break_reasons.php',
    'shopfloor_break_dashboard.php', 'hr_raffle.php', 'hr_organogram.php', 'hr_job_descriptions.php', 'hr_skills.php'
];
$adminFiles = ['company_profile.php', 'requests.php', 'checklists.php', 'app_logs.php'];
$isCurrentFile = static function ($file) use ($currentFile) {
    return $currentFile === $file;
};
$isCurrentGroup = static function (array $files) use ($currentFile) {
    return in_array($currentFile, $files, true);
};
$gtHasAuthenticatedShell = !empty($user);
$resolvedBodyClass = trim('gt-body ' . (isset($bodyClass) ? (string) $bodyClass : 'bg-light') . ($gtHasAuthenticatedShell ? ' gt-authenticated' : ' gt-guest'));

header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="pt-PT">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#212124">
    <title><?= isset($pageTitle) ? h($pageTitle) . ' · ' : '' ?>gesTISSER</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/styles.css" rel="stylesheet">
    <link href="assets/mapper-theme.css" rel="stylesheet">
</head>
<body class="<?= h($resolvedBodyClass) ?>">
<?php if ($user): ?>
<div class="gt-app-shell">
    <aside class="gt-sidebar" id="gtSidebar" aria-label="Navegação principal">
        <div class="gt-brand">
            <a class="gt-brand-logo-shell" href="<?= h(route_url('home', 'dashboard.php')) ?>" aria-label="Abrir visão geral">
                <?php if ($navbarLogo): ?>
                    <img src="<?= h($navbarLogo) ?>" alt="Logótipo empresa" class="gt-brand-logo">
                <?php else: ?>
                    <span class="gt-brand-monogram">T</span>
                    <strong>gesTISSER</strong>
                <?php endif; ?>
            </a>
            <span class="gt-brand-product">ERP e gestão industrial</span>
        </div>

        <nav class="gt-nav">
            <?php if ($isPinOnlyUser): ?>
                <a class="gt-nav-link<?= $isCurrentFile('shopfloor.php') ? ' is-active' : '' ?>" href="<?= h(route_url('shopfloor', 'shopfloor.php')) ?>">
                    <i class="bi bi-speedometer2"></i><span>Shopfloor</span>
                </a>
                <details class="gt-nav-group"<?= $isCurrentFile('erp.php') ? ' open' : '' ?>>
                    <summary><span><i class="bi bi-boxes"></i>ERP</span><i class="bi bi-chevron-down gt-nav-chevron"></i></summary>
                    <div class="gt-nav-submenu">
                        <a class="<?= $isCurrentFile('erp.php') && $currentErpPage === 'overview' ? 'is-active' : '' ?>" href="<?= h(route_url('erp', 'erp.php')) ?>">Visão geral ERP</a>
                        <a class="<?= $isCurrentFile('erp.php') && $currentErpPage === 'production' ? 'is-active' : '' ?>" href="erp.php?page=production">Planeamento e produção</a>
                        <a class="<?= $isCurrentFile('erp.php') && $currentErpPage === 'warehouse' ? 'is-active' : '' ?>" href="erp.php?page=warehouse">Armazém e stock</a>
                    </div>
                </details>
            <?php else: ?>
                <a class="gt-nav-link<?= $isCurrentFile('dashboard.php') ? ' is-active' : '' ?>" href="<?= h(route_url('home', 'dashboard.php')) ?>">
                    <i class="bi bi-grid-1x2"></i><span>Visão geral</span>
                </a>
                <a class="gt-nav-link<?= $isCurrentFile('shopfloor.php') ? ' is-active' : '' ?>" href="<?= h(route_url('shopfloor', 'shopfloor.php')) ?>">
                    <i class="bi bi-speedometer2"></i><span>Shopfloor</span>
                </a>

                <details class="gt-nav-group"<?= $isCurrentFile('erp.php') ? ' open' : '' ?>>
                    <summary><span><i class="bi bi-boxes"></i>ERP industrial</span><i class="bi bi-chevron-down gt-nav-chevron"></i></summary>
                    <div class="gt-nav-submenu">
                        <?php
                        $erpMenuItems = [
                            'overview' => 'Visão geral ERP',
                            'sales' => 'Comercial',
                            'purchases' => 'Compras',
                            'production' => 'Planeamento e produção',
                            'warehouse' => 'Armazém e stock',
                            'quality' => 'Qualidade',
                            'costs' => 'Custos e margens',
                            'master' => 'Dados mestre',
                            'settings' => 'Configuração ERP',
                            'machines' => 'Máquinas e equipamentos',
                        ];
                        ?>
                        <?php foreach ($erpMenuItems as $erpKey => $erpLabel): ?>
                            <a class="<?= $isCurrentFile('erp.php') && $currentErpPage === $erpKey ? 'is-active' : '' ?>" href="<?= $erpKey === 'overview' ? h(route_url('erp', 'erp.php')) : 'erp.php?page=' . h($erpKey) ?>"><?= h($erpLabel) ?></a>
                        <?php endforeach; ?>
                    </div>
                </details>

                <?php if ($showHrMenu): ?>
                    <details class="gt-nav-group"<?= $isCurrentGroup($hrFiles) ? ' open' : '' ?>>
                        <summary><span><i class="bi bi-people"></i>Recursos humanos</span><i class="bi bi-chevron-down gt-nav-chevron"></i></summary>
                        <div class="gt-nav-submenu">
                            <a class="<?= $isCurrentFile('hr.php') ? 'is-active' : '' ?>" href="hr.php">Visão geral RH</a>
                            <span class="gt-nav-label">Gestão base</span>
                            <a class="<?= $isCurrentFile('users.php') ? 'is-active' : '' ?>" href="users.php">Utilizadores</a>
                            <a class="<?= $isCurrentFile('hr_departments.php') ? 'is-active' : '' ?>" href="hr_departments.php">Departamentos</a>
                            <a class="<?= $isCurrentFile('hr_schedules.php') ? 'is-active' : '' ?>" href="hr_schedules.php">Horários</a>
                            <a class="<?= $isCurrentFile('hr_organogram.php') ? 'is-active' : '' ?>" href="hr_organogram.php">Organograma</a>
                            <a class="<?= $isCurrentFile('hr_job_descriptions.php') ? 'is-active' : '' ?>" href="hr_job_descriptions.php">Cadernos de encargos</a>
                            <a class="<?= $isCurrentFile('hr_skills.php') ? 'is-active' : '' ?>" href="hr_skills.php">Matriz de competências</a>
                            <span class="gt-nav-label">Operação diária</span>
                            <a class="<?= $isCurrentFile('hr_calendar.php') ? 'is-active' : '' ?>" href="hr_calendar.php">Calendário</a>
                            <a class="<?= $isCurrentFile('hr_bank.php') ? 'is-active' : '' ?>" href="hr_bank.php">Banco de horas</a>
                            <a class="<?= $isCurrentFile('hr_absences.php') ? 'is-active' : '' ?>" href="hr_absences.php">Ausências</a>
                            <a class="<?= $isCurrentFile('hr_vacations.php') ? 'is-active' : '' ?>" href="hr_vacations.php">Férias</a>
                            <a class="<?= $isCurrentFile('hr_alerts.php') ? 'is-active' : '' ?>" href="hr_alerts.php">Alertas RH</a>
                            <a class="<?= $isCurrentFile('hr_evaluations.php') ? 'is-active' : '' ?>" href="hr_evaluations.php">Avaliações</a>
                            <a class="<?= $isCurrentFile('hr_evaluation_rules.php') ? 'is-active' : '' ?>" href="hr_evaluation_rules.php">Regras de avaliação</a>
                            <a class="<?= $isCurrentFile('resultados.php') ? 'is-active' : '' ?>" href="resultados.php">Resultados</a>
                            <a class="<?= $isCurrentFile('shopfloor_absence_reasons.php') ? 'is-active' : '' ?>" href="shopfloor_absence_reasons.php">Motivos de ausência</a>
                            <a class="<?= $isCurrentFile('shopfloor_break_reasons.php') ? 'is-active' : '' ?>" href="shopfloor_break_reasons.php">Pausas e paragens</a>
                            <a class="<?= $isCurrentFile('shopfloor_break_dashboard.php') ? 'is-active' : '' ?>" href="shopfloor_break_dashboard.php">Dashboard de pausas</a>
                        </div>
                    </details>
                <?php endif; ?>

                <?php if ((int) ($user['is_admin'] ?? 0) === 1): ?>
                    <details class="gt-nav-group"<?= $isCurrentGroup($adminFiles) ? ' open' : '' ?>>
                        <summary><span><i class="bi bi-sliders"></i>Administração</span><i class="bi bi-chevron-down gt-nav-chevron"></i></summary>
                        <div class="gt-nav-submenu">
                            <a class="<?= $isCurrentFile('company_profile.php') ? 'is-active' : '' ?>" href="company_profile.php">Empresa e branding</a>
                            <a class="<?= $isCurrentFile('requests.php') ? 'is-active' : '' ?>" href="requests.php">Gerar formulários</a>
                            <a class="<?= $isCurrentFile('checklists.php') ? 'is-active' : '' ?>" href="checklists.php">Checklists</a>
                            <a class="<?= $isCurrentFile('app_logs.php') ? 'is-active' : '' ?>" href="app_logs.php">Logs da aplicação</a>
                        </div>
                    </details>
                <?php endif; ?>
            <?php endif; ?>
        </nav>

        <div class="gt-sidebar-footer">
            <div class="gt-system-state"><span class="gt-status-dot"></span><span>Sistema operacional</span></div>
            <div class="gt-user-card">
                <div class="gt-user-avatar"><?= h(strtoupper(substr((string) ($user['name'] ?? 'U'), 0, 1))) ?></div>
                <div>
                    <strong><?= h((string) ($user['name'] ?? 'Utilizador')) ?></strong>
                    <small><?= (int) ($user['is_admin'] ?? 0) === 1 ? 'Administrador' : h((string) ($user['access_profile'] ?? 'Utilizador')) ?></small>
                </div>
            </div>
            <a class="gt-logout-link" href="logout.php"><i class="bi bi-box-arrow-right"></i><span>Sair</span></a>
        </div>
    </aside>
    <button type="button" class="gt-sidebar-backdrop" data-gt-sidebar-close aria-label="Fechar menu"></button>

    <div class="gt-main">
        <header class="gt-topbar">
            <button type="button" class="gt-icon-button gt-mobile-menu" data-gt-sidebar-toggle aria-controls="gtSidebar" aria-expanded="false" aria-label="Abrir menu">
                <i class="bi bi-list"></i>
            </button>
            <div class="gt-page-heading">
                <p>Centro operacional</p>
                <h1><?= h(isset($pageTitle) ? (string) $pageTitle : 'gesTISSER') ?></h1>
            </div>
            <div class="gt-top-actions">
                <?php if (isset($navbarClockControl) && is_array($navbarClockControl)): ?>
                    <form method="post" action="<?= h((string) ($navbarClockControl['form_action'] ?? 'shopfloor.php')) ?>" class="gt-clock-form">
                        <input type="hidden" name="action" value="clock_entry">
                        <input type="hidden" name="entry_type" value="<?= h((string) ($navbarClockControl['entry_type'] ?? 'entrada')) ?>">
                        <button type="submit" class="btn btn-sm fw-semibold <?= h((string) ($navbarClockControl['button_class'] ?? 'btn-primary')) ?>">
                            <i class="bi bi-clock-history"></i>
                            <span><?= h((string) ($navbarClockControl['button_label'] ?? 'Ponto de entrada')) ?></span>
                        </button>
                        <?php if (!empty($navbarClockControl['latest_time_label'])): ?>
                            <small><?= h((string) $navbarClockControl['latest_time_label']) ?></small>
                        <?php endif; ?>
                    </form>
                <?php endif; ?>
                <?php if (isset($navbarBreakControl) && is_array($navbarBreakControl)): ?>
                    <?php $activeBreak = $navbarBreakControl['active_break'] ?? null; ?>
                    <button
                        type="button"
                        class="btn btn-sm fw-semibold <?= $activeBreak ? 'btn-danger' : 'btn-success' ?>"
                        data-bs-toggle="modal"
                        data-bs-target="#navbarBreakModal"
                    >
                        <i class="bi <?= $activeBreak ? 'bi-stop-circle' : 'bi-cup-hot' ?>"></i>
                        <span><?= $activeBreak ? 'Terminar pausa/paragem' : 'Iniciar pausa/paragem' ?></span>
                    </button>
                <?php endif; ?>
                <div class="gt-top-user">
                    <span><?= h((string) ($user['name'] ?? 'Utilizador')) ?></span>
                    <small><?= (int) ($user['is_admin'] ?? 0) === 1 ? 'Admin' : h((string) ($user['access_profile'] ?? 'Utilizador')) ?></small>
                </div>
            </div>
        </header>

<?php if (isset($navbarBreakControl) && is_array($navbarBreakControl)): ?>
    <?php $activeBreak = $navbarBreakControl['active_break'] ?? null; ?>
    <div
        class="modal fade"
        id="navbarBreakModal"
        tabindex="-1"
        aria-labelledby="navbarBreakModalLabel"
        aria-hidden="true"
        <?= $activeBreak ? 'data-bs-backdrop="static" data-bs-keyboard="false"' : '' ?>
        <?= $activeBreak ? ('data-break-started-at="' . h((string) ($activeBreak['started_at'] ?? '')) . '"') : '' ?>
        <?= $activeBreak ? ('data-break-elapsed-seconds="' . (int) ($activeBreak['elapsed_seconds'] ?? 0) . '"') : '' ?>
    >
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="post" action="<?= h((string) ($navbarBreakControl['form_action'] ?? 'shopfloor.php')) ?>">
                    <div class="modal-header">
                        <h2 class="modal-title fs-5" id="navbarBreakModalLabel"><?= $activeBreak ? 'Terminar pausa/paragem' : 'Iniciar pausa/paragem' ?></h2>
                        <?php if (!$activeBreak): ?>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                        <?php endif; ?>
                    </div>
                    <div class="modal-body">
                        <?php if ($activeBreak): ?>
                            <input type="hidden" name="action" value="stop_break">
                            <p class="mb-2"><strong><?= h((string) ($activeBreak['break_type'] ?? 'Pausa')) ?></strong> em curso: <?= h((string) ($activeBreak['code'] ?? '')) ?> | <?= h((string) ($activeBreak['label'] ?? '')) ?></p>
                            <p class="small text-secondary">Iniciada às <?= h(date('H:i', strtotime((string) ($activeBreak['started_at'] ?? 'now')))) ?>.</p>
                            <div class="display-5 fw-bold text-center text-danger mb-3" id="navbarBreakElapsed">00:00:00</div>
                            <div class="mb-2 <?= (int) ($activeBreak['requires_comment'] ?? 0) === 1 ? '' : 'd-none' ?>">
                                <label class="form-label">Comentário</label>
                                <textarea class="form-control" name="break_comment" rows="3" placeholder="Comentário obrigatório para terminar" <?= (int) ($activeBreak['requires_comment'] ?? 0) === 1 ? 'required' : '' ?>></textarea>
                            </div>
                        <?php else: ?>
                            <input type="hidden" name="action" value="start_break">
                            <div class="mb-3">
                                <label class="form-label">Tipo de pausa/paragem</label>
                                <select class="form-select" name="break_reason_id" id="navbarBreakReasonSelect" required>
                                    <option value="">Selecionar</option>
                                    <?php foreach (($navbarBreakControl['reason_options'] ?? []) as $breakReasonOption): ?>
                                        <option
                                            value="<?= (int) $breakReasonOption['id'] ?>"
                                            data-requires-comment="<?= (int) ($breakReasonOption['requires_comment'] ?? 0) ?>"
                                        >
                                            <?= h((string) $breakReasonOption['code']) ?> | <?= h((string) $breakReasonOption['label']) ?> (<?= h((string) $breakReasonOption['break_type']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-2 d-none" id="navbarBreakCommentWrap">
                                <label class="form-label">Comentário</label>
                                <textarea class="form-control" name="break_comment" id="navbarBreakComment" rows="3" placeholder="Opcional no início (pode completar antes de terminar)"></textarea>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <?php if (!$activeBreak): ?>
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <?php endif; ?>
                        <button type="submit" class="btn fw-semibold <?= $activeBreak ? 'btn-danger' : 'btn-success' ?>">
                            <?= $activeBreak ? 'Terminar' : 'Iniciar' ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const modalElement = document.getElementById('navbarBreakModal');
            const reasonSelect = document.getElementById('navbarBreakReasonSelect');
            const commentWrap = document.getElementById('navbarBreakCommentWrap');
            const commentField = document.getElementById('navbarBreakComment');
            const elapsedElement = document.getElementById('navbarBreakElapsed');
            let timerIntervalId = null;

            if (reasonSelect && commentWrap && commentField) {
                const syncCommentVisibility = () => {
                    const selectedOption = reasonSelect.options[reasonSelect.selectedIndex] || null;
                    const requiresComment = selectedOption ? selectedOption.getAttribute('data-requires-comment') === '1' : false;
                    commentWrap.classList.toggle('d-none', !requiresComment);
                    commentField.required = false;
                    if (!requiresComment) {
                        commentField.value = '';
                    }
                };

                reasonSelect.addEventListener('change', syncCommentVisibility);
                syncCommentVisibility();
            }

            if (modalElement && elapsedElement && modalElement.dataset.breakElapsedSeconds) {
                const initialElapsedSeconds = Math.max(0, Number.parseInt(modalElement.dataset.breakElapsedSeconds || '0', 10) || 0);
                const clientTimerStartedAt = Date.now();
                const formatSeconds = (seconds) => {
                    const safeSeconds = Math.max(0, seconds);
                    const hours = Math.floor(safeSeconds / 3600);
                    const minutes = Math.floor((safeSeconds % 3600) / 60);
                    const remainingSeconds = safeSeconds % 60;
                    return String(hours).padStart(2, '0') + ':' + String(minutes).padStart(2, '0') + ':' + String(remainingSeconds).padStart(2, '0');
                };

                const renderElapsed = () => {
                    const clientElapsedSeconds = Math.floor((Date.now() - clientTimerStartedAt) / 1000);
                    const elapsedSeconds = initialElapsedSeconds + Math.max(0, clientElapsedSeconds);
                    elapsedElement.textContent = formatSeconds(elapsedSeconds);
                };

                renderElapsed();
                timerIntervalId = window.setInterval(renderElapsed, 1000);

                const showActiveBreakModal = () => {
                    if (!window.bootstrap || !window.bootstrap.Modal) {
                        return;
                    }
                    const modalInstance = window.bootstrap.Modal.getOrCreateInstance(modalElement);
                    modalInstance.show();
                };

                showActiveBreakModal();
            }

            window.addEventListener('beforeunload', () => {
                if (timerIntervalId !== null) {
                    window.clearInterval(timerIntervalId);
                }
            });
        });
    </script>
<?php endif; ?>
        <main class="gt-content" id="gtMainContent">
<?php else: ?>
    <main class="gt-guest-main" id="gtMainContent">
<?php endif; ?>
