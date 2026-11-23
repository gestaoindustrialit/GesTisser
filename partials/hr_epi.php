<?php
$view = ['tab' => $tab] + array_filter($filters);
if ($embed) {
    $view['embed'] = '1';
}
$pdfLink = static function (array $params) {
    unset($params['embed']);
    return 'hr_epi.php?' . http_build_query($params);
};
?>
<section class="vstack gap-3">
<?php if (!$embed): ?>
    <h1 class="h2 mb-0"><?= h($pageTitle) ?></h1>
    <nav class="nav nav-tabs" aria-label="Gestão de EPI">
        <?php foreach ($tabs as $key => $label): ?>
            <a class="nav-link<?= $tab === $key ? ' active' : '' ?>" href="hr_epi.php?tab=<?= h($key) ?>"<?= $tab === $key ? ' aria-current="page"' : '' ?>><?= h($label) ?></a>
        <?php endforeach; ?>
    </nav>
    <?php foreach (['epi_success' => 'success', 'epi_error' => 'danger'] as $key => $style): ?>
        <?php if ($message = flash($key)): ?>
            <div class="alert alert-<?= $style ?> mb-0" role="status"><?= h($message) ?></div>
        <?php endif; ?>
    <?php endforeach; ?>
<?php endif; ?>

<?php if ($operation): ?>
    <article class="soft-card p-3">
        <h2 class="h4"><?= $operation['confirmed_at'] ? 'Receção confirmada' : 'Pendente de confirmação' ?></h2>
        <p><?= h(trim($operation['employee_number'] . ' · ' . $operation['employee_name'], ' ·')) ?> · <?= h(HrEpiPdf::date($operation['delivered_at'])) ?></p>
        <div class="table-responsive">
            <table class="table align-middle">
                <thead><tr><th>Equipamento</th><th>Referência</th><th>Quantidade</th><th>Tamanho</th><th>Motivo</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr><td><?= h($row['name']) ?></td><td><?= h($row['reference'] ?: '—') ?></td>
                        <td><?= h($row['quantity']) ?></td><td><?= h($row['size'] ?: '—') ?></td><td><?= h($row['reason']) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p><?= h($operation['declaration'] ?? HrEpi::DECLARATION) ?></p>
        <?php if ($operation['confirmed_at']): ?>
            <p><?= h($operation['confirmation_method']) ?> · <?= h(HrEpiPdf::date($operation['confirmed_at'])) ?></p>
            <?php if ($canManage || $ownsOperation): ?>
                <a class="btn btn-outline-primary" href="<?= h($pdfLink(['tab' => 'history', 'operation' => $operationId, 'pdf' => 'proof'])) ?>">Comprovativo PDF</a>
            <?php endif; ?>
        <?php elseif ($canConfirm): ?>
            <form method="post" class="vstack gap-3">
                <?= csrf_input() ?>
                <input type="hidden" name="declaration_version" value="<?= h(HrEpi::DECLARATION_VERSION) ?>">
                <label class="form-check"><input class="form-check-input" type="checkbox" name="accept" required value="1"> Aceito a declaração de receção.</label>
                <label class="form-label mb-0">PIN individual de <?= h($operation['employee_name']) ?>
                    <input class="form-control" type="password" name="pin" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="off" required>
                </label>
                <div><button name="action" value="confirm" class="btn btn-primary">Confirmar receção</button>
                    <a class="btn btn-outline-secondary" href="hr_epi.php?tab=<?= $canDeliver ? 'new' : 'history' ?>">Confirmar mais tarde</a></div>
            </form>
        <?php endif; ?>
    </article>
    <?php if ($canManage || $ownsOperation): ?>
        <article class="soft-card p-3">
            <h2 class="h5">Devoluções e retificações</h2>
            <?php foreach ($rows as $row): ?>
                <div class="border-top py-2">
                    <h3 class="h6"><?= h($row['name']) ?> · <?= h($row['returned']) ?>/<?= h($row['quantity']) ?> devolvidos</h3>
                    <?php foreach ($events[$row['id']] ?? [] as $event): ?>
                        <p class="mb-1"><strong><?= $event['kind'] === 'return' ? 'Devolução de ' . h($event['quantity']) : 'Retificação' ?></strong>
                            · <?= h(HrEpiPdf::date($event['created_at'])) ?> · <?= h($event['actor_name']) ?><br><?= nl2br(h($event['notes'])) ?></p>
                    <?php endforeach; ?>
                    <?php if ($canManage): ?>
                        <details class="my-2">
                            <summary>Registar retificação</summary>
                            <form method="post" class="vstack gap-2 mt-2">
                                <?= csrf_input() ?><input type="hidden" name="item" value="<?= h($row['id']) ?>">
                                <label>Dados corrigidos e motivo<textarea class="form-control" name="notes" maxlength="2000" required></textarea></label>
                                <div><button class="btn btn-outline-primary" name="action" value="correction">Guardar retificação</button></div>
                            </form>
                        </details>
                        <?php if ($row['returned'] < $row['quantity']): ?>
                            <details class="my-2">
                                <summary>Registar devolução</summary>
                                <form method="post" class="vstack gap-2 mt-2">
                                    <?= csrf_input() ?><input type="hidden" name="item" value="<?= h($row['id']) ?>">
                                    <label>Quantidade devolvida<input type="number" class="form-control" name="quantity" min="1" max="<?= h($row['quantity'] - $row['returned']) ?>" required></label>
                                    <label>Motivo da devolução<textarea class="form-control" name="notes" maxlength="2000" required></textarea></label>
                                    <div><button class="btn btn-outline-primary" name="action" value="return">Guardar devolução</button></div>
                                </form>
                            </details>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </article>
    <?php endif; ?>

<?php elseif ($tab === 'new'): ?>
    <article class="soft-card p-3">
        <?php if (!$catalog || !$employees): ?>
            <p class="mb-0"><?= !$catalog ? 'O catálogo precisa de pelo menos um equipamento ativo.' : 'Sem colaboradores ativos disponíveis.' ?></p>
        <?php else: ?>
            <form method="post" class="vstack gap-3" id="epi-delivery">
                <?= csrf_input() ?>
                <label class="form-label">Colaborador e número interno
                    <select class="form-select" name="employee" required>
                        <option value="">Selecionar colaborador</option>
                        <?php foreach ($employees as $person): ?>
                            <option value="<?= h($person['id']) ?>"><?= h($employeeLabel($person)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div id="epi-lines" class="vstack gap-2"></div>
                <template id="epi-line-template">
                    <fieldset class="border rounded p-3">
                        <legend class="float-none w-auto px-1 fs-6">Equipamento entregue</legend>
                        <div class="row gy-2">
                            <label class="col-md-5">Equipamento
                                <select class="form-select" data-field="epi_id" required>
                                    <option value="">Selecionar equipamento</option>
                                    <?php foreach ($catalog as $equipment): ?>
                                        <option value="<?= h($equipment['id']) ?>"><?= h(trim($equipment['name'] . ' · ' . $equipment['reference'] . ' · ' . $equipment['size'], ' ·')) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="col-6 col-md-2">Quantidade<input class="form-control" data-field="quantity" type="number" min="1" max="9999" value="1" required></label>
                            <label class="col-6 col-md-2">Tamanho<input class="form-control" data-field="size" maxlength="255"></label>
                            <label class="col-md-3">Motivo
                                <select class="form-select" data-field="reason">
                                    <?php foreach (HrEpi::REASONS as $reason): ?><option><?= h($reason) ?></option><?php endforeach; ?>
                                </select>
                            </label>
                            <label class="col-md-9">Observações<textarea class="form-control" data-field="notes" maxlength="2000" rows="1"></textarea></label>
                            <div class="col-md-3 align-self-end"><button type="button" class="btn btn-outline-secondary" data-remove-line>Remover equipamento</button></div>
                        </div>
                    </fieldset>
                </template>
                <div class="d-flex flex-wrap gap-2">
                    <button type="button" class="btn btn-outline-primary" id="epi-add-line">Adicionar equipamento</button>
                    <button class="btn btn-primary" name="action" value="deliver">Guardar entrega</button>
                </div>
            </form>
            <script src="assets/hr-epi.js" defer></script>
        <?php endif; ?>
    </article>

<?php elseif ($tab === 'catalog'): ?>
    <?php
    $catalogFields = ['name' => 'Designação', 'reference' => 'Referência', 'model' => 'Marca/modelo', 'size' => 'Tamanho',
        'standard' => 'Norma EN', 'replacement_days' => 'Substituição (dias)'];
    $selected = ['active' => 1];
    foreach ($catalog as $equipment) {
        if ($equipment['id'] === ($_GET['edit'] ?? '')) {
            $selected = $equipment;
            break;
        }
    }
    ?>
    <article class="soft-card p-3">
        <h2 class="h5"><?= isset($selected['id']) ? 'Editar equipamento' : 'Adicionar equipamento' ?></h2>
        <form method="post" class="row g-3">
            <?= csrf_input() ?><input type="hidden" name="id" value="<?= h($selected['id'] ?? '') ?>">
            <?php foreach ($catalogFields as $field => $label): ?>
                <label class="col-md-4"><?= h($label) ?><input class="form-control" name="<?= h($field) ?>" value="<?= h($selected[$field] ?? '') ?>"
                    <?= $field === 'replacement_days' ? 'type="number" min="1" max="36500"' : 'maxlength="255"' ?> <?= $field === 'name' ? 'required' : '' ?>></label>
            <?php endforeach; ?>
            <label class="col-md-4">Categoria<select class="form-select" name="category" required>
                <?php foreach (HrEpi::CATEGORIES as $category): ?>
                    <option <?= ($selected['category'] ?? '') === $category ? 'selected' : '' ?>><?= h($category) ?></option>
                <?php endforeach; ?>
            </select></label>
            <label class="col-md-8">Observações<textarea class="form-control" name="notes" maxlength="2000"><?= h($selected['notes'] ?? '') ?></textarea></label>
            <div class="col-12">
                <label class="form-check mb-2"><input class="form-check-input" type="checkbox" name="active" value="1" <?= $selected['active'] ? 'checked' : '' ?>> Equipamento ativo</label>
                <button class="btn btn-primary" name="action" value="catalog">Guardar equipamento</button>
                <?php if (isset($selected['id'])): ?><a class="btn btn-outline-secondary" href="hr_epi.php?tab=catalog">Novo equipamento</a><?php endif; ?>
            </div>
        </form>
    </article>
    <article class="soft-card p-3 table-responsive">
        <table class="table table-sm align-middle">
            <thead><tr><th>Equipamento</th><th>Categoria</th><th>Estado</th><th>SharePoint</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($catalog as $equipment): ?>
                <tr><td><?= h($equipment['name']) ?> <small><?= h($equipment['reference']) ?></small></td><td><?= h($equipment['category']) ?></td>
                    <td><?= $equipment['active'] ? 'Ativo' : 'Inativo' ?></td><td><?= h(HrEpi::syncStatus($equipment)) ?></td>
                    <td><a href="hr_epi.php?tab=catalog&amp;edit=<?= h($equipment['id']) ?>">Editar</a></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </article>
<?php else: ?>
    <article class="soft-card p-3">
        <form method="get" class="row g-2 align-items-end">
            <input type="hidden" name="tab" value="<?= h($tab) ?>">
            <?php if ($embed): ?>
                <input type="hidden" name="embed" value="1"><input type="hidden" name="employee" value="<?= h($filters['employee']) ?>">
            <?php elseif ($canManage): ?>
                <label class="col-md-3">Colaborador
                    <select class="form-select" name="employee"><option value="">Todos</option>
                        <?php foreach ($employees as $person): ?>
                            <option value="<?= h($person['id']) ?>" <?= (int) $filters['employee'] === (int) $person['id'] ? 'selected' : '' ?>><?= h($employeeLabel($person)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endif; ?>
            <label class="col-md-3">Equipamento
                <select class="form-select" name="equipment"><option value="">Todos</option>
                    <?php foreach ($catalog as $equipment): ?>
                        <option value="<?= h($equipment['id']) ?>" <?= $filters['equipment'] === $equipment['id'] ? 'selected' : '' ?>><?= h($equipment['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="col-md-2">Categoria
                <select class="form-select" name="category"><option value="">Todas</option>
                    <?php foreach (HrEpi::CATEGORIES as $category): ?>
                        <option <?= $filters['category'] === $category ? 'selected' : '' ?>><?= h($category) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php foreach (['from' => 'De', 'to' => 'Até'] as $key => $label): ?>
                <label class="col-6 col-md-2"><?= h($label) ?><input name="<?= h($key) ?>" class="form-control" type="date" value="<?= h($filters[$key]) ?>"></label>
            <?php endforeach; ?>
            <div class="col-auto"><button class="btn btn-primary">Filtrar</button></div>
            <?php if ($tab === 'history' && $filters['employee']): ?>
                <div class="col-auto"><a class="btn btn-outline-primary" href="<?= h($pdfLink($view + ['pdf' => 'history'])) ?>" target="_blank" rel="noopener">Exportar PDF</a></div>
            <?php endif; ?>
        </form>
    </article>
    <?php if ($tab === 'dashboard'): ?>
        <?php
        $today = HrEpi::now();
        $operations = $month = $pending = $people = $upcoming = [];
        foreach ($rows as $row) {
            $operations[$row['operation_id']] = true;
            $people[$row['employee_id']] = true;
            if (substr($row['delivered_at'], 0, 7) === $today->format('Y-m')) {
                $month[$row['operation_id']] = true;
            }
            if (!$row['confirmed_at']) {
                $pending[$row['operation_id']] = true;
            }
            if ($replaceable($row) && $row['replacement_date'] >= $today->format('Y-m-d')
                && $row['replacement_date'] <= $today->modify('+30 days')->format('Y-m-d')) {
                $upcoming[] = $row;
            }
        }
        $indicators = ['Total de entregas' => count($operations), 'Entregas no mês' => count($month),
            'Confirmações pendentes' => count($pending), 'Colaboradores com EPI' => count($people),
            'Substituições nos próximos 30 dias' => count($upcoming)];
        ?>
        <div class="row g-2">
            <?php foreach ($indicators as $label => $count): ?>
                <div class="col-6 col-lg"><article class="soft-card h-100 p-3">
                    <p class="text-muted small mb-1"><?= h($label) ?></p><p class="h3 mb-0"><?= h($count) ?></p>
                </article></div>
            <?php endforeach; ?>
        </div>
        <?php $rows = $upcoming; ?>
    <?php endif; ?>
    <article class="soft-card p-3">
        <?php if (!$rows): ?><p class="mb-0"><?= $tab === 'dashboard' ? 'Sem substituições previstas nos próximos 30 dias.' : 'Sem entregas para os filtros selecionados.' ?></p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead><tr><th>Data</th><?php if ($canManage && !$filters['employee']): ?><th>Colaborador</th><?php endif; ?>
                        <th>EPI</th><th>Quantidade</th><th>Motivo</th><th>Estado</th><th>Substituição</th>
                        <?php if ($canManage): ?><th>SharePoint</th><?php endif; ?><th>Entrega</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?= h(HrEpiPdf::date($row['delivered_at'])) ?></td>
                            <?php if ($canManage && !$filters['employee']): ?><td><?= h($row['employee_name']) ?></td><?php endif; ?>
                            <td><?= h($row['name']) ?><?= $row['size'] ? ' · ' . h($row['size']) : '' ?></td>
                            <td><?= h($row['quantity']) ?><?= $row['returned'] ? ' (' . h($row['returned']) . ' devolvidos)' : '' ?></td>
                            <td><?= h($row['reason']) ?></td><td><?= h($status($row)) ?></td>
                            <td><?= $replaceable($row) ? h(HrEpiPdf::date($row['replacement_date'], 'd/m/Y')) : '—' ?></td>
                            <?php if ($canManage): ?><td><?= h(HrEpi::syncStatus($row)) ?></td><?php endif; ?>
                            <td><a href="hr_epi.php?tab=history&amp;operation=<?= h($row['operation_id']) ?>"<?= $embed ? ' target="_blank" rel="noopener"' : '' ?>>Consultar</a>
                                <?php if ($row['confirmed_at']): ?>
                                    · <a href="<?= h($pdfLink(['tab' => 'history', 'operation' => $row['operation_id'], 'pdf' => 'proof'])) ?>" target="_blank" rel="noopener">PDF</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </article>
<?php endif; ?>

<?php if ($canManage && !$embed && !$operation && in_array($tab, ['dashboard', 'catalog', 'history'], true)): ?>
    <form method="post">
        <?= csrf_input() ?><button class="btn btn-outline-primary" name="action" value="sync">Forçar sincronização</button>
    </form>
<?php endif; ?>
</section>
