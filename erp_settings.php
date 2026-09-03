<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/erp_migrations.php';
require_login();

$userId = (int) $_SESSION['user_id'];
if (!is_admin($pdo, $userId)) {
    redirect('dashboard.php');
}

erp_run_phase1_migrations($pdo);
$flashSuccess = null;
$flashError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_or_abort(false)) {
        $flashError = 'Pedido inválido. Atualize a página e tente novamente.';
    } else {
        $allowNegativeStock = isset($_POST['allow_negative_stock']) && $_POST['allow_negative_stock'] === '1';
        $codePattern = trim((string) ($_POST['raw_material_code_pattern'] ?? ''));
        $sequenceIds = (array) ($_POST['sequence_id'] ?? []);
        $prefixes = (array) ($_POST['sequence_prefix'] ?? []);
        $nextNumbers = (array) ($_POST['sequence_next_number'] ?? []);
        $paddings = (array) ($_POST['sequence_padding'] ?? []);
        $suffixes = (array) ($_POST['sequence_suffix'] ?? []);

        if ($codePattern === '' || strpos($codePattern, '{seq}') === false) {
            $flashError = 'O padrão de código das matérias-primas deve incluir {seq}.';
        } else {
            try {
                $pdo->beginTransaction();
                $saveSetting = $pdo->prepare('INSERT INTO erp_settings(key,value,updated_by,updated_at) VALUES (?,?,?,CURRENT_TIMESTAMP) ON CONFLICT(key) DO UPDATE SET value=excluded.value, updated_by=excluded.updated_by, updated_at=CURRENT_TIMESTAMP');
                $saveSetting->execute(['allow_negative_stock', $allowNegativeStock ? '1' : '0', $userId]);
                $saveSetting->execute(['raw_material_code_pattern', $codePattern, $userId]);

                $saveSequence = $pdo->prepare('UPDATE erp_number_sequences SET prefix=?, next_number=?, padding=?, suffix=?, updated_at=CURRENT_TIMESTAMP WHERE id=?');
                foreach ($sequenceIds as $index => $rawId) {
                    $id = (int) $rawId;
                    $nextNumber = max(1, (int) ($nextNumbers[$index] ?? 1));
                    $padding = min(12, max(1, (int) ($paddings[$index] ?? 5)));
                    $saveSequence->execute([
                        trim((string) ($prefixes[$index] ?? '')),
                        $nextNumber,
                        $padding,
                        trim((string) ($suffixes[$index] ?? '')),
                        $id,
                    ]);
                }
                erp_audit($pdo, $userId, 'update', 'erp_settings', null, [], ['allow_negative_stock' => $allowNegativeStock, 'raw_material_code_pattern' => $codePattern]);
                $pdo->commit();
                $flashSuccess = 'Configuração do ERP atualizada com sucesso.';
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $flashError = 'Não foi possível guardar a configuração do ERP.';
            }
        }
    }
}

$settings = $pdo->query('SELECT key, value FROM erp_settings')->fetchAll(PDO::FETCH_KEY_PAIR);
$sequences = $pdo->query('SELECT id, code, prefix, next_number, padding, suffix FROM erp_number_sequences ORDER BY code')->fetchAll(PDO::FETCH_ASSOC);
$sequenceLabels = [
    'customer' => 'Clientes',
    'finished_product' => 'Produtos acabados',
    'raw_material' => 'Matérias-primas',
    'stock_movement' => 'Movimentos de stock',
    'supplier' => 'Fornecedores',
    'work_order' => 'Ordens de fabrico',
];

$pageTitle = 'Configuração ERP';
require __DIR__ . '/partials/header.php';
?>
<h1 class="h3 mb-2">Configuração ERP</h1>
<p class="text-muted">Defina regras transversais do ERP e a numeração automática dos documentos.</p>

<?php if ($flashSuccess): ?><div class="alert alert-success"><?= h($flashSuccess) ?></div><?php endif; ?>
<?php if ($flashError): ?><div class="alert alert-danger"><?= h($flashError) ?></div><?php endif; ?>

<form method="post" class="card shadow-sm soft-card">
    <?= csrf_input() ?>
    <div class="card-body p-4">
        <h2 class="h5">Regras de stock e codificação</h2>
        <div class="row g-3 align-items-end">
            <div class="col-lg-7">
                <label class="form-label" for="raw-material-code-pattern">Padrão do código de matéria-prima</label>
                <input class="form-control" id="raw-material-code-pattern" name="raw_material_code_pattern" required value="<?= h((string) ($settings['raw_material_code_pattern'] ?? '{tipo}{caracteristica}{largura}{gramagem}{seq}')) ?>">
                <div class="form-text">Marcadores disponíveis: {tipo}, {caracteristica}, {largura}, {gramagem} e {seq}. O marcador {seq} é obrigatório.</div>
            </div>
            <div class="col-lg-5 pb-2">
                <input type="hidden" name="allow_negative_stock" value="0">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" id="allow-negative-stock" name="allow_negative_stock" value="1" <?= ($settings['allow_negative_stock'] ?? '0') === '1' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="allow-negative-stock">Permitir movimentos que originem stock negativo</label>
                </div>
            </div>
        </div>

        <hr class="my-4">
        <h2 class="h5">Sequências de numeração</h2>
        <p class="small text-muted">O próximo número é usado no documento seguinte. A largura adiciona zeros à esquerda.</p>
        <div class="table-responsive">
            <table class="table align-middle">
                <thead><tr><th>Documento</th><th>Prefixo</th><th>Próximo número</th><th>Largura</th><th>Sufixo</th><th>Exemplo</th></tr></thead>
                <tbody>
                <?php foreach ($sequences as $sequence): ?>
                    <?php $example = (string) $sequence['prefix'] . str_pad((string) $sequence['next_number'], (int) $sequence['padding'], '0', STR_PAD_LEFT) . (string) $sequence['suffix']; ?>
                    <tr>
                        <td><input type="hidden" name="sequence_id[]" value="<?= (int) $sequence['id'] ?>"><strong><?= h($sequenceLabels[$sequence['code']] ?? $sequence['code']) ?></strong><div class="small text-muted"><?= h($sequence['code']) ?></div></td>
                        <td><input class="form-control" name="sequence_prefix[]" value="<?= h((string) $sequence['prefix']) ?>"></td>
                        <td><input class="form-control" type="number" min="1" name="sequence_next_number[]" value="<?= (int) $sequence['next_number'] ?>" required></td>
                        <td><input class="form-control" type="number" min="1" max="12" name="sequence_padding[]" value="<?= (int) $sequence['padding'] ?>" required></td>
                        <td><input class="form-control" name="sequence_suffix[]" value="<?= h((string) $sequence['suffix']) ?>"></td>
                        <td><code><?= h($example) ?></code></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <button class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Guardar configuração ERP</button>
    </div>
</form>
<?php require __DIR__ . '/partials/footer.php'; ?>
