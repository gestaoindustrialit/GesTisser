<?php
require_once __DIR__ . '/helpers.php';
require_login();

$userId = (int) $_SESSION['user_id'];
if (!can_access_hr_module($pdo, $userId)) {
    http_response_code(403);
    exit('Acesso reservado a administradores e equipa RH.');
}

$flashSuccess = null;
$flashError = null;

function valid_schedule_time($value): bool
{
    return $value !== null && preg_match('/^\d{2}:\d{2}$/', $value) === 1;
}

function nullable_schedule_time(string $value)
{
    $value = trim($value);
    return $value === '' ? null : $value;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create_schedule' || $action === 'update_schedule') {
        $id = (int) ($_POST['schedule_id'] ?? 0);
        $parentScheduleId = (int) ($_POST['parent_schedule_id'] ?? 0);
        if ($action === 'update_schedule' && $parentScheduleId <= 0) {
            $parentScheduleId = (int) ($_POST['current_parent_schedule_id'] ?? 0);
        }
        if ($parentScheduleId === $id) {
            $parentScheduleId = 0;
        }
        $name = trim((string) ($_POST['name'] ?? ''));
        $startTime = trim((string) ($_POST['start_time'] ?? ''));
        $endTime = trim((string) ($_POST['end_time'] ?? ''));
        $secondStartTime = nullable_schedule_time((string) ($_POST['second_start_time'] ?? ''));
        $secondEndTime = nullable_schedule_time((string) ($_POST['second_end_time'] ?? ''));
        $weekdays = $_POST['weekdays'] ?? [];
        $weekdays = is_array($weekdays) ? array_values(array_intersect(['1', '2', '3', '4', '5', '6', '7'], array_map('strval', $weekdays))) : [];
        $weekdaysMask = implode(',', $weekdays);

        $hasSecondPeriod = $secondStartTime !== null || $secondEndTime !== null;

        if ($weekdays === []) {
            $flashError = 'Selecione pelo menos um dia para o intervalo.';
        } elseif ($name === '' || !valid_schedule_time($startTime) || !valid_schedule_time($endTime) || ($hasSecondPeriod && (!valid_schedule_time($secondStartTime) || !valid_schedule_time($secondEndTime)))) {
            $flashError = 'Preencha Entrada 1 e Saída 1 com horas válidas (HH:MM). Se usar Entrada 2, preencha também Saída 2.';
        } elseif (!$hasSecondPeriod && !($startTime < $endTime)) {
            $flashError = 'A sequência do horário deve ser Entrada 1 < Saída 1.';
        } elseif ($hasSecondPeriod && !($startTime < $endTime && $endTime <= $secondStartTime && $secondStartTime < $secondEndTime)) {
            $flashError = 'A sequência do horário deve ser Entrada 1 < Saída 1 <= Entrada 2 < Saída 2.';
        } else {
            try {
                if ($parentScheduleId > 0) {
                    $parentStmt = $pdo->prepare('SELECT COUNT(*) FROM hr_schedules WHERE id = ? AND parent_schedule_id IS NULL');
                    $parentStmt->execute([$parentScheduleId]);
                    if ((int) $parentStmt->fetchColumn() === 0) {
                        throw new RuntimeException('Horário principal inválido.');
                    }
                }

                if ($action === 'create_schedule') {
                    $stmt = $pdo->prepare('INSERT INTO hr_schedules(name, start_time, end_time, second_start_time, second_end_time, break_minutes, weekdays_mask, parent_schedule_id) VALUES (?, ?, ?, ?, ?, 0, ?, ?)');
                    $stmt->execute([$name, $startTime, $endTime, $secondStartTime, $secondEndTime, $weekdaysMask, $parentScheduleId > 0 ? $parentScheduleId : null]);
                    $flashSuccess = 'Horário criado com sucesso.';
                } else {
                    $stmt = $pdo->prepare('UPDATE hr_schedules SET name = ?, start_time = ?, end_time = ?, second_start_time = ?, second_end_time = ?, break_minutes = 0, weekdays_mask = ?, parent_schedule_id = ? WHERE id = ?');
                    $stmt->execute([$name, $startTime, $endTime, $secondStartTime, $secondEndTime, $weekdaysMask, $parentScheduleId > 0 ? $parentScheduleId : null, $id]);
                    $flashSuccess = 'Horário atualizado com sucesso.';
                }
            } catch (PDOException $e) {
                $flashError = 'Não foi possível guardar horário (nome duplicado).';
            } catch (RuntimeException $e) {
                $flashError = $e->getMessage();
            }
        }
    } elseif ($action === 'delete_schedule') {
        $id = (int) ($_POST['schedule_id'] ?? 0);

        if ($id <= 0) {
            $flashError = 'Horário inválido.';
        } else {
            $assignedUsersStmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE schedule_id = ?');
            $assignedUsersStmt->execute([$id]);
            $assignedUsersCount = (int) $assignedUsersStmt->fetchColumn();

            $variantStmt = $pdo->prepare('SELECT COUNT(*) FROM hr_schedules WHERE parent_schedule_id = ?');
            $variantStmt->execute([$id]);
            $variantCount = (int) $variantStmt->fetchColumn();

            if ($assignedUsersCount > 0) {
                $flashError = 'Não é possível eliminar um horário associado a utilizadores.';
            } elseif ($variantCount > 0) {
                $flashError = 'Não é possível eliminar um horário com variantes associadas.';
            } else {
                $deleteStmt = $pdo->prepare('DELETE FROM hr_schedules WHERE id = ?');
                $deleteStmt->execute([$id]);
                $flashSuccess = 'Horário eliminado com sucesso.';
            }
        }
    }
}

$schedules = $pdo->query('SELECT id, name, start_time, end_time, second_start_time, second_end_time, weekdays_mask, parent_schedule_id, created_at FROM hr_schedules ORDER BY name COLLATE NOCASE ASC')->fetchAll(PDO::FETCH_ASSOC);
$mainSchedules = array_values(array_filter($schedules, static function (array $schedule): bool {
    return (int) ($schedule['parent_schedule_id'] ?? 0) === 0;
}));
$weekdayLabels = ['1' => 'Seg', '2' => 'Ter', '3' => 'Qua', '4' => 'Qui', '5' => 'Sex', '6' => 'Sáb', '7' => 'Dom'];

$pageTitle = 'Horários';
require __DIR__ . '/partials/header.php';
?>
<a href="hr.php" class="btn btn-link px-0">&larr; Voltar ao módulo RH</a>
<h1 class="h3 mb-3">Gestão de horários</h1>

<?php if ($flashSuccess): ?><div class="alert alert-success"><?= h($flashSuccess) ?></div><?php endif; ?>
<?php if ($flashError): ?><div class="alert alert-danger"><?= h($flashError) ?></div><?php endif; ?>

<div class="card shadow-sm mb-4">
    <div class="card-header bg-white"><h2 class="h6 mb-0">Novo horário</h2></div>
    <div class="card-body">
        <form method="post" class="row g-3">
            <input type="hidden" name="action" value="create_schedule">
            <div class="col-md-3"><label class="form-label">Nome</label><input class="form-control" name="name" placeholder="Ex.: Turno Geral" required></div>
            <div class="col-md-2"><label class="form-label">Entrada 1</label><input class="form-control" type="time" name="start_time" required></div>
            <div class="col-md-2"><label class="form-label">Saída 1</label><input class="form-control" type="time" name="end_time" required></div>
            <div class="col-md-2"><label class="form-label">Entrada 2</label><input class="form-control" type="time" name="second_start_time"></div>
            <div class="col-md-2"><label class="form-label">Saída 2</label><input class="form-control" type="time" name="second_end_time"></div>
            <div class="col-md-12">
                <label class="form-label d-block">Dias</label>
                <?php foreach ($weekdayLabels as $value => $label): ?>
                    <label class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="weekdays[]" value="<?= $value ?>" <?= (int) $value <= 5 ? 'checked' : '' ?>><span class="form-check-label"><?= $label ?></span></label>
                <?php endforeach; ?>
            </div>
            <div class="col-md-4"><label class="form-label">Adicionar a um turno</label><select class="form-select" name="parent_schedule_id"><option value="0">Criar um novo turno</option><?php foreach ($mainSchedules as $baseSchedule): ?><option value="<?= (int) $baseSchedule['id'] ?>"><?= h((string) $baseSchedule['name']) ?></option><?php endforeach; ?></select><div class="form-text">Selecione um turno para lhe adicionar um intervalo aplicável noutros dias.</div></div><div class="col-12"><button class="btn btn-primary">Criar horário</button></div>
        </form>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-header bg-white"><h2 class="h6 mb-0">Horários existentes</h2></div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead><tr><th>Nome</th><th>Horário</th><th>Dias</th><th>Tipo</th><th>Editar</th></tr></thead>
                <tbody>
                <?php foreach ($schedules as $schedule): ?>
                    <?php $mask = array_filter(explode(',', (string) $schedule['weekdays_mask'])); ?>
                    <tr>
                        <td><?= h($schedule['name']) ?></td>
                        <td><?= h(format_schedule_periods($schedule)) ?></td>
                        <td><?= h(implode(', ', array_map(static function ($d) use ($weekdayLabels) { return $weekdayLabels[$d] ?? $d; }, $mask))) ?></td>
                        <td><?= (int) ($schedule['parent_schedule_id'] ?? 0) > 0 ? '<span class="badge text-bg-info">Variante</span>' : '<span class="badge text-bg-secondary">Principal</span>' ?></td>
                        <td>
                            <form method="post" class="row g-1">
                                <input type="hidden" name="action" value="update_schedule">
                                <input type="hidden" name="schedule_id" value="<?= (int) $schedule['id'] ?>">
                                <input type="hidden" name="current_parent_schedule_id" value="<?= (int) ($schedule['parent_schedule_id'] ?? 0) ?>">
                                <div class="col-md-2"><input class="form-control form-control-sm" name="name" value="<?= h($schedule['name']) ?>" required></div>
                                <div class="col-md-2"><input class="form-control form-control-sm" type="time" name="start_time" value="<?= h((string) $schedule['start_time']) ?>" required></div>
                                <div class="col-md-2"><input class="form-control form-control-sm" type="time" name="end_time" value="<?= h((string) $schedule['end_time']) ?>" required></div>
                                <div class="col-md-2"><input class="form-control form-control-sm" type="time" name="second_start_time" value="<?= h((string) ($schedule['second_start_time'] ?? '')) ?>"></div>
                                <div class="col-md-2"><input class="form-control form-control-sm" type="time" name="second_end_time" value="<?= h((string) ($schedule['second_end_time'] ?? '')) ?>"></div>
                                <div class="col-md-2"><button class="btn btn-sm btn-outline-secondary w-100">Guardar</button></div>
                                <div class="col-12">
                                    <span class="small text-muted me-2">Dias:</span>
                                    <?php foreach ($weekdayLabels as $value => $label): ?>
                                        <label class="form-check form-check-inline mb-0"><input class="form-check-input" type="checkbox" name="weekdays[]" value="<?= $value ?>" <?= in_array($value, $mask, true) ? 'checked' : '' ?>><span class="form-check-label small"><?= $label ?></span></label>
                                    <?php endforeach; ?>
                                </div>
                            </form>
                            <?php if ((int) ($schedule['parent_schedule_id'] ?? 0) === 0): ?>
                                <details class="mt-2">
                                    <summary class="btn btn-sm btn-outline-primary">+ Adicionar intervalo de horas</summary>
                                    <form method="post" class="row g-2 mt-1 p-2 border rounded bg-light">
                                        <input type="hidden" name="action" value="create_schedule">
                                        <input type="hidden" name="parent_schedule_id" value="<?= (int) $schedule['id'] ?>">
                                        <div class="col-md-3"><label class="form-label small">Nome do intervalo</label><input class="form-control form-control-sm" name="name" value="<?= h((string) $schedule['name']) ?> - " placeholder="Ex.: Sexta-feira" required></div>
                                        <div class="col-md-2"><label class="form-label small">Entrada 1</label><input class="form-control form-control-sm" type="time" name="start_time" required></div>
                                        <div class="col-md-2"><label class="form-label small">Saída 1</label><input class="form-control form-control-sm" type="time" name="end_time" required></div>
                                        <div class="col-md-2"><label class="form-label small">Entrada 2</label><input class="form-control form-control-sm" type="time" name="second_start_time"></div>
                                        <div class="col-md-2"><label class="form-label small">Saída 2</label><input class="form-control form-control-sm" type="time" name="second_end_time"></div>
                                        <div class="col-12"><span class="small text-muted me-2">Aplicar em:</span><?php foreach ($weekdayLabels as $value => $label): ?><label class="form-check form-check-inline mb-0"><input class="form-check-input" type="checkbox" name="weekdays[]" value="<?= $value ?>"><span class="form-check-label small"><?= $label ?></span></label><?php endforeach; ?></div>
                                        <div class="col-12"><button class="btn btn-sm btn-primary">Adicionar ao turno</button></div>
                                    </form>
                                </details>
                            <?php endif; ?>
                            <form method="post" class="mt-2" onsubmit="return confirm('Eliminar este horário?');">
                                <input type="hidden" name="action" value="delete_schedule">
                                <input type="hidden" name="schedule_id" value="<?= (int) $schedule['id'] ?>">
                                <input type="hidden" name="current_parent_schedule_id" value="<?= (int) ($schedule['parent_schedule_id'] ?? 0) ?>">
                                <button class="btn btn-sm btn-outline-danger w-100">Eliminar</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
