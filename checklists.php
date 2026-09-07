<?php
require_once __DIR__ . '/helpers.php';
require_login();

$userId = (int) $_SESSION['user_id'];
$flashSuccess = null;
$flashError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_abort();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create_template') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $itemLabels = (array) ($_POST['item_label'] ?? []);
        $itemTypes = (array) ($_POST['item_type'] ?? []);
        $itemOptions = (array) ($_POST['item_options'] ?? []);
        $itemRequired = (array) ($_POST['item_required'] ?? []);

        if ($name === '') {
            $flashError = 'O modelo precisa de um nome.';
        } else {
            $items = [];
            $allowedTypes = ['checkbox', 'text', 'textarea', 'number', 'date', 'select'];
            foreach ($itemLabels as $index => $label) {
                $label = trim((string) $label);
                $type = (string) ($itemTypes[$index] ?? 'checkbox');
                if ($label === '' || !in_array($type, $allowedTypes, true)) continue;
                $options = array_values(array_filter(array_map('trim', explode(',', (string) ($itemOptions[$index] ?? ''))), static function ($option) { return $option !== ''; }));
                if ($type === 'select' && !$options) {
                    $flashError = 'Os campos de escolha precisam de pelo menos uma opção.';
                    break;
                }
                $items[] = ['content' => $label, 'type' => $type, 'options' => $options, 'required' => isset($itemRequired[$index])];
            }

            if (!$flashError && !$items) {
                $flashError = 'Adicione pelo menos um item para o checklist.';
            } elseif (!$flashError) {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('INSERT INTO checklist_templates(name, description, created_by) VALUES (?, ?, ?)');
                $stmt->execute([$name, $description !== '' ? $description : null, $userId]);
                $templateId = (int) $pdo->lastInsertId();

                $itemStmt = $pdo->prepare('INSERT INTO checklist_template_items(template_id, content, position, field_type, options_json, is_required) VALUES (?, ?, ?, ?, ?, ?)');
                foreach ($items as $index => $item) {
                    $itemStmt->execute([$templateId, $item['content'], $index + 1, $item['type'], $item['options'] ? json_encode($item['options'], JSON_UNESCAPED_UNICODE) : null, $item['required'] ? 1 : 0]);
                }
                $pdo->commit();
                $flashSuccess = 'Checklist criada com sucesso.';
            }
        }
    }

    if ($action === 'delete_template') {
        $templateId = (int) ($_POST['template_id'] ?? 0);
        if ($templateId > 0) {
            $stmt = $pdo->prepare('DELETE FROM checklist_templates WHERE id = ? AND created_by = ?');
            $stmt->execute([$templateId, $userId]);
            if ($stmt->rowCount() > 0) {
                $flashSuccess = 'Checklist removida com sucesso.';
            } else {
                $flashError = 'Não foi possível remover esta checklist (apenas o autor pode remover).';
            }
        }
    }
}

$templatesStmt = $pdo->query('SELECT ct.*, u.name AS creator_name FROM checklist_templates ct INNER JOIN users u ON u.id = ct.created_by ORDER BY ct.created_at DESC');
$templates = $templatesStmt->fetchAll(PDO::FETCH_ASSOC);

$templateItemsStmt = $pdo->query('SELECT * FROM checklist_template_items ORDER BY template_id ASC, position ASC, id ASC');
$templateItemsById = [];
foreach ($templateItemsStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
    $templateItemsById[(int) $item['template_id']][] = $item;
}

$pageTitle = 'Checklists';
require __DIR__ . '/partials/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="h3 mb-1">Modelos de checklist</h1>
        <p class="text-muted mb-0">Crie checklists reutilizáveis para tarefas e recorrências.</p>
    </div>
</div>

<?php if ($flashSuccess): ?><div class="alert alert-success"><?= h($flashSuccess) ?></div><?php endif; ?>
<?php if ($flashError): ?><div class="alert alert-danger"><?= h($flashError) ?></div><?php endif; ?>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card shadow-sm soft-card">
            <div class="card-header bg-white"><h2 class="h5 mb-0">Nova checklist</h2></div>
            <div class="card-body">
                <form method="post" class="vstack gap-2">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="create_template">
                    <input class="form-control" name="name" placeholder="Nome" required>
                    <input class="form-control" name="description" placeholder="Descrição (opcional)">
                    <div id="checklist-items" class="vstack gap-2"></div>
                    <button class="btn btn-outline-secondary" type="button" id="add-checklist-item">+ Adicionar campo</button>
                    <button class="btn btn-primary">Guardar checklist</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card shadow-sm soft-card">
            <div class="card-header bg-white"><h2 class="h5 mb-0">Checklists criadas</h2></div>
            <div class="card-body vstack gap-2">
                <?php foreach ($templates as $template): ?>
                    <div class="border rounded p-3">
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <div>
                                <h3 class="h6 mb-1"><?= h($template['name']) ?></h3>
                                <?php if ((string) ($template['description'] ?? '') !== ''): ?><p class="small text-muted mb-1"><?= h((string) $template['description']) ?></p><?php endif; ?>
                                <small class="text-muted">Criada por <?= h($template['creator_name']) ?></small>
                            </div>
                            <?php if ((int) $template['created_by'] === $userId): ?>
                                <form method="post" onsubmit="return confirm('Remover checklist?');">
                                    <?= csrf_input() ?>
                                    <input type="hidden" name="action" value="delete_template">
                                    <input type="hidden" name="template_id" value="<?= (int) $template['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger">Remover</button>
                                </form>
                            <?php endif; ?>
                        </div>
                        <ul class="mb-0 mt-2">
                            <?php foreach (($templateItemsById[(int) $template['id']] ?? []) as $item): ?>
                                <li><span class="badge text-bg-light border"><?= h(['checkbox'=>'Sim/Não','text'=>'Texto curto','textarea'=>'Texto longo','number'=>'Número','date'=>'Data','select'=>'Escolha'][$item['field_type'] ?? 'checkbox'] ?? 'Sim/Não') ?></span> <?= h($item['content']) ?><?= !empty($item['is_required']) ? ' *' : '' ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endforeach; ?>
                <?php if (!$templates): ?><div class="text-muted">Sem checklists criadas.</div><?php endif; ?>
            </div>
        </div>
    </div>
</div>

<template id="checklist-item-template">
    <div class="checklist-field-row border rounded p-2">
        <div class="row g-2 align-items-center">
            <div class="col-md-5"><input class="form-control" name="item_label[]" placeholder="Pergunta ou indicação" required></div>
            <div class="col-md-3"><select class="form-select checklist-field-type" name="item_type[]"><option value="checkbox">Sim / Não</option><option value="text">Texto curto</option><option value="textarea">Texto longo</option><option value="number">Número</option><option value="date">Data</option><option value="select">Lista de escolha</option></select></div>
            <div class="col-md-3"><input class="form-control checklist-options d-none" name="item_options[]" placeholder="Opções separadas por vírgulas"></div>
            <div class="col-md-1"><button class="btn btn-outline-danger w-100 remove-checklist-item" type="button" aria-label="Remover campo">×</button></div>
            <div class="col-12"><label class="form-check"><input class="form-check-input" type="checkbox" name="item_required[]" value="1" checked> Resposta obrigatória</label></div>
        </div>
    </div>
</template>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const list = document.getElementById('checklist-items');
    const template = document.getElementById('checklist-item-template');
    let nextItemIndex = 0;
    function addItem() {
        const row = template.content.firstElementChild.cloneNode(true);
        const index = nextItemIndex++;
        row.querySelector('[name="item_required[]"]').name = 'item_required[' + index + ']';
        row.querySelector('.checklist-field-type').addEventListener('change', function () { row.querySelector('.checklist-options').classList.toggle('d-none', this.value !== 'select'); });
        row.querySelector('.remove-checklist-item').addEventListener('click', function () { if (list.children.length > 1) row.remove(); });
        list.appendChild(row);
    }
    document.getElementById('add-checklist-item').addEventListener('click', addItem);
    addItem();
});
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
