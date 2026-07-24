<?php
require_once __DIR__ . '/hr_organization_lib.php';

require_login();
gt_run_org_migrations($pdo);

$userId = (int) $_SESSION['user_id'];
if (!gt_is_hr_allowed($pdo, $userId)) {
    http_response_code(403);
    exit('Acesso reservado a RH.');
}

$flashSuccess = null;
$flashError = null;

function gt_org_time_label(?string $time): string
{
    $value = trim((string) $time);
    if ($value === '') {
        return '--:--';
    }

    return substr($value, 0, 5);
}

function gt_org_department_label(array $person): string
{
    $department = trim((string) ($person['department'] ?? ''));
    if ($department !== '') {
        return $department;
    }

    $profile = trim((string) ($person['access_profile'] ?? ''));
    return $profile !== '' ? $profile : 'Sem departamento';
}

function gt_org_role_label(array $person): string
{
    foreach (['job_title', 'title', 'profession', 'access_profile'] as $field) {
        $value = trim((string) ($person[$field] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    return 'Função por preencher';
}

function gt_org_person_json(array $person): string
{
    return h(json_encode([
        'id' => (int) $person['id'],
        'name' => (string) ($person['name'] ?? ''),
        'role' => gt_org_role_label($person),
        'department' => gt_org_department_label($person),
        'manager' => (string) ($person['manager_name_resolved'] ?? 'Sem superior'),
        'schedule' => (string) ($person['schedule_name'] ?? 'Sem turno'),
        'capacity' => (int) ($person['capacity_percent'] ?? 100),
        'email' => trim((string) ($person['professional_email'] ?? '')) ?: 'Por preencher',
        'phone' => trim((string) ($person['professional_phone'] ?? '')) ?: 'Por preencher',
        'notes' => trim((string) ($person['hr_notes'] ?? '')) ?: 'Sem notas',
    ], JSON_UNESCAPED_UNICODE));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_or_abort(false)) {
        $flashError = 'Pedido inválido.';
    } else {
        $id = (int) ($_POST['user_id'] ?? 0);
        $managerId = (int) ($_POST['manager_user_id'] ?? 0);
        $managerId = $managerId > 0 ? $managerId : null;

        if ($id <= 0) {
            $flashError = 'Pessoa inválida.';
        } elseif (!gt_prevents_cycle($pdo, $id, $managerId)) {
            $flashError = 'Hierarquia circular impedida.';
        } else {
            $old = $pdo->prepare('SELECT manager_user_id, job_title, department, schedule_id, capacity_percent, professional_phone, professional_email, hr_notes FROM users WHERE id = ?');
            $old->execute([$id]);
            $before = $old->fetch(PDO::FETCH_ASSOC) ?: [];

            $stmt = $pdo->prepare('UPDATE users SET manager_user_id = ?, job_title = ?, department = ?, schedule_id = ?, capacity_percent = ?, professional_phone = ?, professional_email = ?, hr_notes = ? WHERE id = ?');
            $stmt->execute([
                $managerId,
                trim((string) ($_POST['job_title'] ?? '')),
                trim((string) ($_POST['department'] ?? '')),
                (int) ($_POST['schedule_id'] ?? 0) ?: null,
                max(0, min(100, (int) ($_POST['capacity_percent'] ?? 100))),
                trim((string) ($_POST['professional_phone'] ?? '')),
                trim((string) ($_POST['professional_email'] ?? '')),
                trim((string) ($_POST['hr_notes'] ?? '')),
                $id,
            ]);
            gt_org_audit($pdo, $userId, 'hr.organogram.update', 'users', $id, $before, $_POST);
            $flashSuccess = 'Organograma atualizado.';
        }
    }
}

$deps = $pdo->query('SELECT * FROM hr_departments ORDER BY name COLLATE NOCASE ASC')->fetchAll(PDO::FETCH_ASSOC);
$schedules = $pdo->query('SELECT * FROM hr_schedules ORDER BY name COLLATE NOCASE ASC')->fetchAll(PDO::FETCH_ASSOC);
$dep = trim((string) ($_GET['department'] ?? ''));
$sch = (int) ($_GET['schedule_id'] ?? 0);
$q = trim((string) ($_GET['q'] ?? ''));
$showInactive = (int) ($_GET['inactive'] ?? 0);
$selectedId = (int) ($_GET['person_id'] ?? 0);

$where = [];
$args = [];
if (!$showInactive) {
    $where[] = 'COALESCE(u.is_active, 1) = 1';
}
if ($dep !== '') {
    $where[] = 'u.department = ?';
    $args[] = $dep;
}
if ($sch > 0) {
    $where[] = 'u.schedule_id = ?';
    $args[] = $sch;
}
if ($q !== '') {
    $where[] = '(u.name LIKE ? OR u.job_title LIKE ? OR u.title LIKE ? OR u.profession LIKE ? OR u.access_profile LIKE ? OR u.department LIKE ?)';
    array_push($args, "%$q%", "%$q%", "%$q%", "%$q%", "%$q%", "%$q%");
}

$sql = 'SELECT u.*, s.name AS schedule_name, s.start_time, s.end_time, s.weekdays_mask, m.name AS manager_name_resolved
    FROM users u
    LEFT JOIN hr_schedules s ON s.id = u.schedule_id
    LEFT JOIN users m ON m.id = u.manager_user_id'
    . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
    . ' ORDER BY COALESCE(u.manager_user_id, 0), COALESCE(u.org_sort_order, 0), u.name COLLATE NOCASE ASC';
$st = $pdo->prepare($sql);
$st->execute($args);
$people = $st->fetchAll(PDO::FETCH_ASSOC);
$peopleById = [];
$byManager = [];
foreach ($people as $person) {
    $pid = (int) $person['id'];
    $peopleById[$pid] = $person;
}
foreach ($people as $person) {
    $managerId = (int) ($person['manager_user_id'] ?? 0);
    if ($managerId > 0 && !isset($peopleById[$managerId])) {
        $managerId = 0;
    }
    $byManager[$managerId][] = $person;
}

$levels = [];
$visited = [];
$walk = static function (int $managerId, int $level) use (&$walk, &$levels, &$visited, $byManager): void {
    foreach ($byManager[$managerId] ?? [] as $person) {
        $pid = (int) $person['id'];
        if (isset($visited[$pid])) {
            continue;
        }
        $visited[$pid] = true;
        $levels[$level][] = $person;
        $walk($pid, $level + 1);
    }
};
$walk(0, 1);
foreach ($people as $person) {
    $pid = (int) $person['id'];
    if (!isset($visited[$pid])) {
        $levels[1][] = $person;
        $visited[$pid] = true;
    }
}
ksort($levels);

if ($selectedId <= 0 || !isset($peopleById[$selectedId])) {
    foreach ($levels as $levelPeople) {
        if (!empty($levelPeople)) {
            $selectedId = (int) $levelPeople[array_key_last($levelPeople)]['id'];
            break;
        }
    }
}
$selectedPerson = $peopleById[$selectedId] ?? ($people[0] ?? null);

$shiftStats = [];
foreach ($schedules as $schedule) {
    $sid = (int) $schedule['id'];
    $shiftStats[$sid] = [
        'schedule' => (string) $schedule['name'],
        'start' => (string) ($schedule['start_time'] ?? ''),
        'end' => (string) ($schedule['end_time'] ?? ''),
        'fte' => 0.0,
        'people' => 0,
        'departments' => [],
    ];
}
foreach ($people as $person) {
    $sid = (int) ($person['schedule_id'] ?? 0);
    if (!$sid || !isset($shiftStats[$sid]) || (int) ($person['is_active'] ?? 1) !== 1) {
        continue;
    }
    $fte = (int) ($person['capacity_percent'] ?? 100) / 100;
    $departmentLabel = gt_org_department_label($person);
    $shiftStats[$sid]['people']++;
    $shiftStats[$sid]['fte'] += $fte;
    $shiftStats[$sid]['departments'][$departmentLabel] = ($shiftStats[$sid]['departments'][$departmentLabel] ?? 0) + $fte;
}
$shiftStats = array_values(array_filter($shiftStats, static fn (array $stat): bool => $stat['people'] > 0));

$exportRows = array_map(static function (array $person): array {
    return [
        'id' => (int) $person['id'],
        'name' => (string) ($person['name'] ?? ''),
        'job_title' => gt_org_role_label($person),
        'department' => gt_org_department_label($person),
        'manager_user_id' => (int) ($person['manager_user_id'] ?? 0) ?: null,
        'manager_name' => (string) ($person['manager_name_resolved'] ?? ''),
        'schedule' => (string) ($person['schedule_name'] ?? ''),
        'capacity_percent' => (int) ($person['capacity_percent'] ?? 100),
    ];
}, $people);

$pageTitle = 'Organograma';
$bodyClass = 'gt-organogram-page';
require __DIR__ . '/partials/header.php';
?>
<style>
.gt-organogram-page .gt-main { background: #f4f6f8; }
.org-page-shell { margin: -1.5rem; color: #24272d; }
.org-hero { position: sticky; top: 0; z-index: 20; display: flex; gap: 1rem; justify-content: space-between; align-items: center; padding: 1rem 1.25rem; background: rgba(246,247,249,.95); border-bottom: 1px solid #dce2e8; backdrop-filter: blur(12px); }
.org-eyebrow { display: block; color: #62b947; font-size: .72rem; font-weight: 800; letter-spacing: .22em; }
.org-title { margin: .1rem 0 0; font-size: 1.55rem; font-weight: 800; }
.org-actions { display: flex; gap: .65rem; align-items: center; flex-wrap: wrap; justify-content: flex-end; }
.org-search { min-width: 320px; }
.org-section { margin: 0; padding: 1.15rem 1.25rem; background: #fff; border-bottom: 1px solid #dce2e8; box-shadow: 0 1px 2px rgba(16,24,40,.04); }
.org-section-title { margin: 0 0 .75rem; font-size: 1.1rem; font-weight: 800; }
.org-shift-grid { display: grid; grid-template-columns: repeat(4, minmax(220px, 1fr)); gap: .75rem; }
.org-shift-card { padding: 1rem; border: 1px solid #dce2e8; border-left: 6px solid #2d69a1; border-radius: 1rem; background: linear-gradient(180deg,#fff,#fbfcfd); box-shadow: 0 1px 3px rgba(16,24,40,.08); }
.org-shift-card:nth-child(2n) { border-left-color: #65bd48; }
.org-shift-card:nth-child(3n) { border-left-color: #2d8cca; }
.org-shift-card:nth-child(4n) { border-left-color: #1e2931; }
.org-card-top { display: flex; justify-content: space-between; gap: .5rem; }
.org-code { color: #2d69a1; font-size: .72rem; font-weight: 800; letter-spacing: .18em; text-transform: uppercase; }
.org-edit-link { color: #2d69a1; font-size: .76rem; font-weight: 800; text-decoration: none; }
.org-stat-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: .5rem; margin-top: .75rem; }
.org-stat { padding: .65rem; border: 1px solid #e4e8ed; border-radius: .55rem; background: #fff; }
.org-stat strong { display: block; font-size: 1.15rem; line-height: 1; }
.org-stat span { color: #717b86; font-size: .68rem; font-weight: 800; text-transform: uppercase; }
.org-stat-foot { margin-top: .75rem; padding-top: .75rem; border-top: 1px solid #e4e8ed; color: #5f6974; font-size: .82rem; }
.org-content { display: grid; grid-template-columns: minmax(0, 1fr) 340px; gap: 1rem; padding: 1rem 1.25rem 2rem; }
.org-map { min-height: 560px; padding: 1.1rem 1.25rem; border: 1px solid #dce2e8; border-radius: 1rem; background-color: #fff; background-image: linear-gradient(#edf1f5 1px, transparent 1px), linear-gradient(90deg,#edf1f5 1px, transparent 1px); background-size: 24px 24px; }
.org-map-head { display: flex; justify-content: space-between; align-items: start; gap: 1rem; margin-bottom: 1.5rem; }
.org-hint { color: #6b7280; }
.org-level { margin-bottom: 1.5rem; }
.org-level-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: .55rem; color: #2d69a1; font-size: .75rem; font-weight: 900; letter-spacing: .12em; text-transform: uppercase; }
.org-person-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: .75rem; }
.org-person-card { display: block; width: 100%; min-height: 104px; padding: 1rem; text-align: left; border: 1px solid #d7dde4; border-radius: .75rem; background: #fff; box-shadow: 0 1px 2px rgba(16,24,40,.05); color: inherit; text-decoration: none; }
.org-person-card.is-selected { border-color: #65bd48; box-shadow: inset 0 0 0 1px #65bd48; }
.org-person-card strong { display: block; font-weight: 800; }
.org-person-card .role { color: #5f6974; }
.org-person-meta, .org-report { display: block; color: #6b7280; font-size: .78rem; margin-top: .35rem; }
.org-dot { display: inline-block; width: .5rem; height: .5rem; margin-right: .25rem; border-radius: 999px; background: #2d69a1; box-shadow: 0 0 0 2px #e6f0f9; }
.org-person-card.is-selected .org-dot { background: #111827; }
.org-department { display: block; margin-top: .45rem; color: #62b947; font-size: .72rem; font-weight: 900; letter-spacing: .14em; text-transform: uppercase; }
.org-side { position: sticky; top: 5.5rem; align-self: start; padding: 1.25rem; border: 1px solid #dce2e8; border-radius: 1rem; background: #fff; box-shadow: 0 1px 3px rgba(16,24,40,.08); }
.org-detail-list { margin: 1rem 0; }
.org-detail-row { padding: .6rem 0; border-top: 1px solid #dce2e8; }
.org-detail-row span { display: block; color: #6b7280; font-size: .72rem; font-weight: 900; text-transform: uppercase; }
.org-detail-row strong { display: block; font-size: .92rem; }
.org-edit-form { display: none; }
.org-edit-form.is-open { display: grid; gap: .55rem; margin-top: .85rem; }
@media (max-width: 1200px) { .org-shift-grid { grid-template-columns: repeat(2, minmax(220px, 1fr)); } .org-content { grid-template-columns: 1fr; } .org-side { position: static; } }
@media (max-width: 760px) { .org-hero { position: static; align-items: stretch; flex-direction: column; } .org-search { min-width: 100%; } .org-shift-grid { grid-template-columns: 1fr; } .org-page-shell { margin: -1rem; } }
</style>
<div class="org-page-shell">
    <header class="org-hero">
        <div>
            <span class="org-eyebrow">PESSOAS E RESPONSABILIDADES</span>
            <h1 class="org-title">Organograma</h1>
        </div>
        <form class="org-actions" method="get">
            <div class="input-group org-search">
                <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                <input class="form-control" name="q" value="<?= h($q) ?>" placeholder="Pesquisar pessoas, processos, sistemas...">
            </div>
            <select class="form-select" name="department" aria-label="Departamento">
                <option value="">Todos os departamentos</option>
                <?php foreach ($deps as $department): ?>
                    <option value="<?= h((string) $department['name']) ?>" <?= $dep === (string) $department['name'] ? 'selected' : '' ?>><?= h((string) $department['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <select class="form-select" name="schedule_id" aria-label="Turno">
                <option value="0">Todos os turnos</option>
                <?php foreach ($schedules as $schedule): ?>
                    <option value="<?= (int) $schedule['id'] ?>" <?= $sch === (int) $schedule['id'] ? 'selected' : '' ?>><?= h((string) $schedule['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <label class="form-check-label small text-nowrap"><input class="form-check-input" type="checkbox" name="inactive" value="1" <?= $showInactive ? 'checked' : '' ?>> Inativos</label>
            <button class="btn btn-light border fw-bold">Filtrar</button>
            <button class="btn btn-light border fw-bold" type="button" id="orgExportJson">Exportar JSON</button>
            <a class="btn btn-primary fw-bold" href="users.php"><i class="bi bi-plus-lg"></i> Adicionar</a>
        </form>
    </header>

    <?php if ($flashSuccess): ?><div class="alert alert-success m-3 mb-0"><?= h($flashSuccess) ?></div><?php endif; ?>
    <?php if ($flashError): ?><div class="alert alert-danger m-3 mb-0"><?= h($flashError) ?></div><?php endif; ?>

    <section class="org-section">
        <h2 class="org-section-title">Capacidade disponível por turno</h2>
        <div class="org-shift-grid">
            <?php if (empty($shiftStats)): ?>
                <div class="text-muted">Sem capacidade associada aos filtros atuais.</div>
            <?php endif; ?>
            <?php foreach ($shiftStats as $index => $stat): $daily = $stat['fte'] * 8; ?>
                <article class="org-shift-card">
                    <div class="org-card-top">
                        <div><span class="org-code"><?= h(substr((string) $stat['schedule'], 0, 3) ?: 'T' . ($index + 1)) ?></span><h3 class="h6 fw-bold mb-1"><?= h((string) $stat['schedule']) ?></h3></div>
                        <a class="org-edit-link" href="hr_schedules.php">Editar</a>
                    </div>
                    <div class="text-muted small"><?= h(gt_org_time_label($stat['start']) . ' → ' . gt_org_time_label($stat['end'])) ?> · 8 h/dia</div>
                    <div class="org-stat-grid">
                        <div class="org-stat"><strong><?= (int) $stat['people'] ?></strong><span>Pessoas</span></div>
                        <div class="org-stat"><strong><?= number_format((float) $stat['fte'], 0, ',', '.') ?></strong><span>FTE</span></div>
                        <div class="org-stat"><strong><?= number_format($daily, 0, ',', '.') ?> h</strong><span>Capacidade/dia</span></div>
                        <div class="org-stat"><strong><?= number_format($daily * 5, 0, ',', '.') ?> h</strong><span>Capacidade/semana</span></div>
                    </div>
                    <div class="org-stat-foot">
                        <?php $parts = []; foreach ($stat['departments'] as $label => $fte) { $parts[] = h($label) . ': ' . number_format((float) $fte, 0, ',', '.') . ' FTE'; } echo implode(' · ', $parts); ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <main class="org-content">
        <section class="org-map">
            <div class="org-map-head">
                <div><span class="org-eyebrow">ESTRUTURA</span><h2 class="h5 fw-bold mb-0">Organograma funcional</h2></div>
                <span class="org-hint">Clique numa pessoa para editar</span>
            </div>
            <?php foreach ($levels as $level => $levelPeople): ?>
                <div class="org-level">
                    <div class="org-level-head"><span>Nível <?= (int) $level ?></span><span><?= count($levelPeople) ?> <?= count($levelPeople) === 1 ? 'pessoa' : 'pessoas' ?></span></div>
                    <div class="org-person-grid">
                        <?php foreach ($levelPeople as $person): $pid = (int) $person['id']; ?>
                            <a class="org-person-card <?= $pid === $selectedId ? 'is-selected' : '' ?>" href="?person_id=<?= $pid ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?><?= $dep !== '' ? '&department=' . urlencode($dep) : '' ?><?= $sch ? '&schedule_id=' . $sch : '' ?><?= $showInactive ? '&inactive=1' : '' ?>" data-person='<?= gt_org_person_json($person) ?>'>
                                <strong><?= h((string) $person['name']) ?></strong>
                                <span class="role"><?= h(gt_org_role_label($person)) ?></span>
                                <span class="org-person-meta"><span class="org-dot"></span><?= h((string) ($person['schedule_name'] ?: 'Sem turno')) ?> · <?= h(gt_org_time_label($person['start_time'] ?? '') . '-' . gt_org_time_label($person['end_time'] ?? '')) ?> · <?= (int) ($person['capacity_percent'] ?? 100) ?>%</span>
                                <span class="org-report">Reporta a: <?= h((string) ($person['manager_name_resolved'] ?: 'Topo da estrutura')) ?></span>
                                <span class="org-department"><?= h(gt_org_department_label($person)) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </section>

        <aside class="org-side" id="orgDetail">
            <?php if ($selectedPerson): ?>
                <span class="org-eyebrow"><?= h(gt_org_department_label($selectedPerson)) ?></span>
                <h2 class="h4 fw-bold mb-1" data-detail="name"><?= h((string) $selectedPerson['name']) ?></h2>
                <p class="text-muted mb-0" data-detail="role"><?= h(gt_org_role_label($selectedPerson)) ?></p>
                <div class="org-detail-list">
                    <div class="org-detail-row"><span>Reporta a</span><strong data-detail="manager"><?= h((string) ($selectedPerson['manager_name_resolved'] ?: 'Sem superior')) ?></strong></div>
                    <div class="org-detail-row"><span>Equipa direta</span><strong><?= count($byManager[(int) $selectedPerson['id']] ?? []) ?> reportes</strong></div>
                    <div class="org-detail-row"><span>Turno</span><strong data-detail="schedule"><?= h((string) ($selectedPerson['schedule_name'] ?: 'Sem turno')) ?> · <?= h(gt_org_time_label($selectedPerson['start_time'] ?? '') . '-' . gt_org_time_label($selectedPerson['end_time'] ?? '')) ?></strong></div>
                    <div class="org-detail-row"><span>Capacidade</span><strong data-detail="capacity"><?= (int) ($selectedPerson['capacity_percent'] ?? 100) ?>% · 8 h/dia</strong></div>
                    <div class="org-detail-row"><span>Email</span><strong data-detail="email"><?= h(trim((string) ($selectedPerson['professional_email'] ?? '')) ?: 'Por preencher') ?></strong></div>
                    <div class="org-detail-row"><span>Telefone</span><strong data-detail="phone"><?= h(trim((string) ($selectedPerson['professional_phone'] ?? '')) ?: 'Por preencher') ?></strong></div>
                    <div class="org-detail-row"><span>Notas</span><strong data-detail="notes"><?= h(trim((string) ($selectedPerson['hr_notes'] ?? '')) ?: 'Sem notas') ?></strong></div>
                    <div class="org-detail-row"><span>Caderno de função</span><strong>Ainda não criado</strong></div>
                </div>
                <button class="btn btn-primary fw-bold" type="button" id="orgToggleEdit">Editar</button>
                <a class="btn btn-light border fw-bold" href="hr_job_descriptions.php?responsible_user_id=<?= (int) $selectedPerson['id'] ?>">Criar caderno</a>
                <form method="post" class="org-edit-form" id="orgEditForm">
                    <?= csrf_input() ?>
                    <input type="hidden" name="user_id" value="<?= (int) $selectedPerson['id'] ?>">
                    <input class="form-control" name="job_title" value="<?= h((string) ($selectedPerson['job_title'] ?? '')) ?>" placeholder="Função">
                    <input class="form-control" name="department" value="<?= h((string) ($selectedPerson['department'] ?? '')) ?>" placeholder="Departamento">
                    <select class="form-select" name="manager_user_id"><option value="">Sem superior</option><?php foreach ($people as $manager): if ((int) $manager['id'] === (int) $selectedPerson['id']) continue; ?><option value="<?= (int) $manager['id'] ?>" <?= (int) ($selectedPerson['manager_user_id'] ?? 0) === (int) $manager['id'] ? 'selected' : '' ?>><?= h((string) $manager['name']) ?></option><?php endforeach; ?></select>
                    <select class="form-select" name="schedule_id"><option value="">Sem turno</option><?php foreach ($schedules as $schedule): ?><option value="<?= (int) $schedule['id'] ?>" <?= (int) ($selectedPerson['schedule_id'] ?? 0) === (int) $schedule['id'] ? 'selected' : '' ?>><?= h((string) $schedule['name']) ?></option><?php endforeach; ?></select>
                    <input class="form-control" type="number" min="0" max="100" name="capacity_percent" value="<?= h((string) ($selectedPerson['capacity_percent'] ?? 100)) ?>" placeholder="Capacidade %">
                    <input class="form-control" name="professional_email" value="<?= h((string) ($selectedPerson['professional_email'] ?? '')) ?>" placeholder="Email profissional">
                    <input class="form-control" name="professional_phone" value="<?= h((string) ($selectedPerson['professional_phone'] ?? '')) ?>" placeholder="Telefone">
                    <textarea class="form-control" name="hr_notes" placeholder="Notas"><?= h((string) ($selectedPerson['hr_notes'] ?? '')) ?></textarea>
                    <button class="btn btn-primary fw-bold">Guardar alterações</button>
                </form>
            <?php else: ?>
                <p class="text-muted mb-0">Sem pessoas para apresentar.</p>
            <?php endif; ?>
        </aside>
    </main>
</div>
<script>
(() => {
    const exportButton = document.getElementById('orgExportJson');
    if (exportButton) {
        exportButton.addEventListener('click', () => {
            const payload = <?= json_encode($exportRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
            const blob = new Blob([JSON.stringify(payload, null, 2)], { type: 'application/json' });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = 'organograma.json';
            link.click();
            URL.revokeObjectURL(link.href);
        });
    }
    const toggle = document.getElementById('orgToggleEdit');
    const form = document.getElementById('orgEditForm');
    if (toggle && form) {
        toggle.addEventListener('click', () => form.classList.toggle('is-open'));
    }
})();
</script>
<?php require __DIR__ . '/partials/footer.php'; ?>
