<?php
require_once __DIR__ . '/hr_organization_lib.php';
require_login();
gt_run_org_migrations($pdo);
$userId = (int) $_SESSION['user_id'];
$user = current_user($pdo) ?: [];
if (!gt_is_erp_allowed($pdo, $user)) {
    http_response_code(403);
    exit('Acesso reservado ao ERP.');
}

$statusLabels = ['operational' => 'Operacional', 'maintenance' => 'Em manutenção', 'broken' => 'Avariada', 'stopped' => 'Parada', 'inactive' => 'Desativada'];
$criticalityLabels = ['low' => 'Baixa', 'medium' => 'Média', 'high' => 'Alta', 'critical' => 'Crítica'];
$flashSuccess = $flashError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_or_abort(false)) {
        $flashError = 'Pedido inválido.';
    } else {
        try {
            $action = $_POST['action'] ?? '';
            if ($action === 'save') {
                $id = (int) ($_POST['id'] ?? 0);
                $year = (int) ($_POST['manufacturing_year'] ?? 0);
                if ($year && ($year < 1900 || $year > (int) date('Y') + 1)) {
                    throw new RuntimeException('Ano de fabrico inválido.');
                }
                $data = [trim($_POST['code'] ?? ''), trim($_POST['name'] ?? ''), trim($_POST['brand'] ?? ''), trim($_POST['model'] ?? ''), trim($_POST['serial_number'] ?? ''), $year ?: null, (int) ($_POST['department_id'] ?? 0) ?: null, trim($_POST['location'] ?? ''), (int) ($_POST['owner_user_id'] ?? 0) ?: null, trim($_POST['status'] ?? 'operational'), trim($_POST['criticality'] ?? 'medium'), trim($_POST['nominal_capacity'] ?? ''), trim($_POST['capacity_unit'] ?? ''), trim($_POST['cycle_time'] ?? ''), trim($_POST['cycle_time_unit'] ?? ''), max(0, (int) ($_POST['operators_required'] ?? 0)), trim($_POST['supplier'] ?? ''), trim($_POST['service_provider'] ?? ''), trim($_POST['purchase_date'] ?? '') ?: null, trim($_POST['next_maintenance_date'] ?? '') ?: null, trim($_POST['manual_url'] ?? ''), trim($_POST['characteristics'] ?? ''), trim($_POST['risks'] ?? ''), trim($_POST['limitations'] ?? ''), trim($_POST['notes'] ?? ''), $userId];
                if ($id) {
                    $old = $pdo->prepare('SELECT * FROM erp_machines WHERE id=?');
                    $old->execute([$id]);
                    $before = $old->fetch(PDO::FETCH_ASSOC) ?: [];
                    $pdo->prepare('UPDATE erp_machines SET code=?,name=?,brand=?,model=?,serial_number=?,manufacturing_year=?,department_id=?,location=?,owner_user_id=?,status=?,criticality=?,nominal_capacity=?,capacity_unit=?,cycle_time=?,cycle_time_unit=?,operators_required=?,supplier=?,service_provider=?,purchase_date=?,next_maintenance_date=?,manual_url=?,characteristics=?,risks=?,limitations=?,notes=?,updated_by=?,updated_at=CURRENT_TIMESTAMP,is_active=CASE WHEN ?="inactive" THEN 0 ELSE 1 END WHERE id=?')->execute(array_merge($data, [$data[9], $id]));
                    gt_org_audit($pdo, $userId, 'erp.machines.update', 'erp_machines', $id, $before, $_POST);
                    $flashSuccess = 'Máquina atualizada.';
                } else {
                    $pdo->prepare('INSERT INTO erp_machines(code,name,brand,model,serial_number,manufacturing_year,department_id,location,owner_user_id,status,criticality,nominal_capacity,capacity_unit,cycle_time,cycle_time_unit,operators_required,supplier,service_provider,purchase_date,next_maintenance_date,manual_url,characteristics,risks,limitations,notes,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute(array_merge(array_slice($data, 0, 25), [$userId, $userId]));
                    gt_org_audit($pdo, $userId, 'erp.machines.create', 'erp_machines', (int) $pdo->lastInsertId(), [], $_POST);
                    $flashSuccess = 'Máquina criada.';
                }
            } elseif ($action === 'delete') {
                $id = (int) $_POST['id'];
                $pdo->prepare('UPDATE erp_machines SET deleted_at=CURRENT_TIMESTAMP,is_active=0,updated_by=? WHERE id=?')->execute([$userId, $id]);
                gt_org_audit($pdo, $userId, 'erp.machines.delete', 'erp_machines', $id, [], []);
                $flashSuccess = 'Máquina removida.';
            }
        } catch (Throwable $e) {
            $flashError = 'Erro: ' . $e->getMessage();
        }
    }
}

$q = trim($_GET['q'] ?? '');
$status = trim($_GET['status'] ?? '');
$dep = (int) ($_GET['department_id'] ?? 0);
$where = ['m.deleted_at IS NULL'];
$args = [];
if ($q !== '') {
    $where[] = '(m.code LIKE ? OR m.name LIKE ? OR m.brand LIKE ? OR m.model LIKE ? OR m.supplier LIKE ?)';
    for ($i = 0; $i < 5; $i++) $args[] = "%$q%";
}
if ($status !== '') { $where[] = 'm.status=?'; $args[] = $status; }
if ($dep) { $where[] = 'm.department_id=?'; $args[] = $dep; }
$st = $pdo->prepare('SELECT m.*,d.name department_name,u.name owner_name,(SELECT COUNT(*) FROM hr_machine_competencies c JOIN users uu ON uu.id=c.user_id WHERE c.machine_id=m.id AND c.level>=3 AND COALESCE(uu.is_active,1)=1 AND c.deleted_at IS NULL) autonomous FROM erp_machines m LEFT JOIN hr_departments d ON d.id=m.department_id LEFT JOIN users u ON u.id=m.owner_user_id WHERE ' . implode(' AND ', $where) . ' ORDER BY m.is_active DESC,m.code');
$st->execute($args);
$machines = $st->fetchAll(PDO::FETCH_ASSOC);
$deps = $pdo->query('SELECT * FROM hr_departments ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$people = $pdo->query('SELECT id,name FROM users WHERE COALESCE(is_active,1)=1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);

$machineOperational = $machineCritical = $machineNoOwner = 0;
foreach ($machines as $machineCardRow) {
    if ($machineCardRow['status'] === 'operational') $machineOperational++;
    if (in_array($machineCardRow['criticality'], ['high', 'critical'], true)) $machineCritical++;
    if (empty($machineCardRow['owner_user_id'])) $machineNoOwner++;
}
$cards = [
    ['label' => 'Máquinas', 'value' => count($machines), 'hint' => 'Equipamentos registados'],
    ['label' => 'Operacionais', 'value' => $machineOperational, 'hint' => 'Disponíveis para produção'],
    ['label' => 'Críticas', 'value' => $machineCritical, 'hint' => 'Criticidade alta'],
    ['label' => 'Sem responsável', 'value' => $machineNoOwner, 'hint' => 'Atribuição pendente'],
];

$pageTitle = 'Máquinas e equipamentos';
$bodyClass = 'machines-page';
require __DIR__ . '/partials/header.php';
?>
<div class="container-fluid py-4 machines-shell">
    <?php if ($flashSuccess): ?><div class="alert alert-success"><?= h($flashSuccess) ?></div><?php endif; ?>
    <?php if ($flashError): ?><div class="alert alert-danger"><?= h($flashError) ?></div><?php endif; ?>

    <form class="machines-toolbar mb-3">
        <input class="form-control" name="q" value="<?= h($q) ?>" placeholder="Pesquisar máquina, marca, modelo, setor ou fornecedor...">
        <select class="form-select" name="status"><option value="">Todos os estados</option><?php foreach ($statusLabels as $k => $v): ?><option value="<?= h($k) ?>" <?= $status === $k ? 'selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?></select>
        <select class="form-select" name="department_id"><option value="">Todos os departamentos</option><?php foreach ($deps as $d): ?><option value="<?= (int) $d['id'] ?>" <?= $dep === (int) $d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option><?php endforeach; ?></select>
        <button class="btn btn-primary" type="submit">Filtrar</button>
        <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#machineModal">+ Máquina</button>
    </form>

    <div class="row g-3 mb-3"><?php foreach ($cards as $card): ?><div class="col-md-3"><article class="machine-stat-card"><span><?= h($card['label']) ?></span><strong><?= (int) $card['value'] ?></strong><small><?= h($card['hint']) ?></small></article></div><?php endforeach; ?></div>

    <div class="machines-grid">
        <?php foreach ($machines as $m): ?>
            <article class="machine-card">
                <div class="machine-card-head"><div><span class="machine-status-pill"><?= h($statusLabels[$m['status']] ?? $m['status']) ?></span><h2><?= h($m['code'] . ' · ' . $m['name']) ?></h2><p><?= h(trim(($m['brand'] ?? '') . ' ' . ($m['model'] ?? '')) ?: 'Marca e modelo por definir') ?></p></div><span class="criticality <?= h($m['criticality'] ?? 'medium') ?>"><?= h($criticalityLabels[$m['criticality']] ?? $m['criticality']) ?></span></div>
                <div class="machine-meta"><div><span>Localização</span><strong><?= h($m['department_name'] ?: $m['location'] ?: 'Por definir') ?></strong></div><div><span>Responsável</span><strong><?= h($m['owner_name'] ?: 'Por definir') ?></strong></div><div><span>Capacidade nominal</span><strong><?= h(trim(($m['nominal_capacity'] ?? '') . ' ' . ($m['capacity_unit'] ?? '')) ?: 'Por definir') ?></strong></div><div><span>Operadores autónomos</span><strong><?= h((string) $m['autonomous']) ?></strong></div><div><span>N.º série</span><strong><?= h($m['serial_number'] ?: '—') ?></strong></div><div><span>Próxima manutenção</span><strong><?= h($m['next_maintenance_date'] ?: '—') ?></strong></div></div>
                <p class="machine-notes"><?= h($m['notes'] ?: 'Sem observações.') ?></p>
                <div class="machine-actions"><a class="btn btn-outline-secondary" href="hr_skills.php?machine_id=<?= (int) $m['id'] ?>">Competências</a><button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#machineModal" data-machine='<?= h(json_encode($m, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE)) ?>'>Editar</button><form method="post" class="d-inline"><?= csrf_input() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $m['id'] ?>"><button class="btn btn-danger-subtle">Eliminar</button></form></div>
            </article>
        <?php endforeach; ?>
        <?php if (!$machines): ?><div class="machine-empty">Sem máquinas para os filtros selecionados.</div><?php endif; ?>
    </div>
</div>

<div class="modal fade machine-modal" id="machineModal" tabindex="-1" aria-labelledby="machineModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl"><div class="modal-content"><form method="post" id="machineForm"><?= csrf_input() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="">
        <div class="modal-header"><div><p>Ativos produtivos</p><h2 class="modal-title" id="machineModalLabel">Nova máquina</h2></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
        <div class="modal-body"><div class="machine-form-grid">
            <label><span>Código interno</span><input class="form-control" type="text" name="code" required></label>
            <label><span>Designação</span><input class="form-control" type="text" name="name" required></label>
            <label><span>Marca</span><input class="form-control" type="text" name="brand"></label>
            <label><span>Modelo</span><input class="form-control" type="text" name="model"></label>
            <label><span>Número de série</span><input class="form-control" type="text" name="serial_number"></label>
            <label><span>Ano de fabrico</span><input class="form-control" type="number" name="manufacturing_year"></label>
            <label><span>Departamento / setor</span><select class="form-select" name="department_id"><option value="">Por definir</option><?php foreach ($deps as $d): ?><option value="<?= (int) $d['id'] ?>"><?= h($d['name']) ?></option><?php endforeach; ?></select></label>
            <label><span>Localização física</span><input class="form-control" type="text" name="location"></label>
            <label><span>Responsável técnico</span><select class="form-select" name="owner_user_id"><option value="">Por definir</option><?php foreach ($people as $p): ?><option value="<?= (int) $p['id'] ?>"><?= h($p['name']) ?></option><?php endforeach; ?></select></label>
            <label><span>Estado</span><select class="form-select" name="status"><?php foreach ($statusLabels as $k => $v): ?><option value="<?= h($k) ?>"><?= h($v) ?></option><?php endforeach; ?></select></label>
            <label><span>Criticidade</span><select class="form-select" name="criticality"><?php foreach ($criticalityLabels as $k => $v): ?><option value="<?= h($k) ?>" <?= $k === 'medium' ? 'selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?></select></label>
            <label><span>Capacidade nominal / unidade</span><input class="form-control" type="text" name="nominal_capacity"></label>
            <label><span>Tempo de ciclo</span><input class="form-control" type="text" name="cycle_time"></label>
            <label><span>Operadores necessários</span><input class="form-control" type="number" name="operators_required" min="0" value="1"></label>
            <label><span>Fornecedor / assistência</span><input class="form-control" type="text" name="supplier"></label>
            <label><span>Data de aquisição</span><input class="form-control" type="date" name="purchase_date"></label>
            <label><span>Próxima manutenção</span><input class="form-control" type="date" name="next_maintenance_date"></label>
            <label class="full"><span>Manual / ligação documental</span><input class="form-control" type="url" name="manual_url"></label>
            <label class="full"><span>Características, riscos, limitações e observações</span><textarea class="form-control" name="notes" rows="5"></textarea></label>
        </div></div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary">Guardar</button></div>
    </form></div></div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const modal = document.getElementById('machineModal');
    const form = document.getElementById('machineForm');
    const title = document.getElementById('machineModalLabel');
    if (!modal || !form) return;
    modal.addEventListener('show.bs.modal', function (event) {
        form.reset();
        form.querySelector('[name="id"]').value = '';
        title.textContent = 'Nova máquina';
        const data = event.relatedTarget ? event.relatedTarget.getAttribute('data-machine') : null;
        if (!data) return;
        const machine = JSON.parse(data);
        title.textContent = 'Editar máquina';
        Object.keys(machine).forEach(function (key) {
            const field = form.querySelector('[name="' + key + '"]');
            if (field) field.value = machine[key] || '';
        });
        if (form.elements.nominal_capacity) form.elements.nominal_capacity.value = [machine.nominal_capacity || '', machine.capacity_unit || ''].join(' ').trim();
        if (form.elements.cycle_time) form.elements.cycle_time.value = [machine.cycle_time || '', machine.cycle_time_unit || ''].join(' ').trim();
    });
});
</script>
<?php require __DIR__ . '/partials/footer.php'; ?>
