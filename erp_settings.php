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
        $action = (string) ($_POST['action'] ?? 'save_settings');
        try {
            if ($action === 'save_operation_type') {
                $id = (int) ($_POST['id'] ?? 0);
                $code = strtolower(trim((string) ($_POST['code'] ?? '')));
                $name = trim((string) ($_POST['name'] ?? ''));
                if (!preg_match('/^[a-z0-9_-]+$/', $code) || $name === '') throw new InvalidArgumentException('Indique um código simples e um nome para o tipo de operação.');
                $pdo->beginTransaction();
                if ($id) {
                    $old = $pdo->prepare('SELECT code FROM erp_operation_types WHERE id=?'); $old->execute([$id]); $oldCode = $old->fetchColumn();
                    if ($oldCode === false) throw new InvalidArgumentException('Tipo de operação inexistente.');
                    $pdo->prepare('UPDATE erp_operation_types SET code=?,name=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$code,$name,$id]);
                    $pdo->prepare('UPDATE erp_operations SET operation_type=? WHERE operation_type=?')->execute([$code,$oldCode]);
                } else {
                    $pdo->prepare('INSERT INTO erp_operation_types(code,name) VALUES (?,?)')->execute([$code,$name]);
                }
                erp_audit($pdo,$userId,$id?'update':'create','erp_operation_types',$id?:((int)$pdo->lastInsertId()),[],['code'=>$code,'name'=>$name]);
                $pdo->commit(); $flashSuccess = 'Tipo de operação guardado com sucesso.';
            } elseif ($action === 'delete_operation_type') {
                $id = (int) ($_POST['id'] ?? 0); $stmt=$pdo->prepare('SELECT code FROM erp_operation_types WHERE id=?');$stmt->execute([$id]);$code=$stmt->fetchColumn();
                if ($code === false) throw new InvalidArgumentException('Tipo de operação inexistente.');
                $used=$pdo->prepare('SELECT COUNT(*) FROM erp_operations WHERE operation_type=?');$used->execute([$code]);
                if ((int)$used->fetchColumn()>0) throw new DomainException('Não é possível remover um tipo associado a operações.');
                $pdo->prepare('DELETE FROM erp_operation_types WHERE id=?')->execute([$id]);
                erp_audit($pdo,$userId,'delete','erp_operation_types',$id,['code'=>$code],[]); $flashSuccess='Tipo de operação removido com sucesso.';
            } elseif ($action === 'save_operation_sector') {
                $id=(int)($_POST['id']??0);$code=strtoupper(trim((string)($_POST['code']??'')));$name=trim((string)($_POST['name']??''));
                if ($code===''||$name==='') throw new InvalidArgumentException('O código e o nome do setor são obrigatórios.');
                $values=[$code,$name,max(0,(float)str_replace(',','.',(string)($_POST['hourly_rate']??0))),!empty($_POST['is_active'])?1:0];
                if($id){$pdo->prepare('UPDATE erp_work_centers SET code=?,name=?,hourly_rate=?,is_active=? WHERE id=?')->execute(array_merge($values,[$id]));}
                else{$pdo->prepare('INSERT INTO erp_work_centers(code,name,hourly_rate,is_active) VALUES (?,?,?,?)')->execute($values);$id=(int)$pdo->lastInsertId();}
                erp_audit($pdo,$userId,(int)($_POST['id']??0)?'update':'create','erp_work_centers',$id,[],['code'=>$code,'name'=>$name]);$flashSuccess='Setor de operações guardado com sucesso.';
            } elseif ($action === 'delete_operation_sector') {
                $id=(int)($_POST['id']??0);$references=['erp_operations'=>'default_work_center_id','erp_machines'=>'work_center_id','erp_article_routing_steps'=>'work_center_id','erp_shift_assignments'=>'work_center_id'];
                foreach($references as $table=>$column){$stmt=$pdo->prepare("SELECT COUNT(*) FROM $table WHERE $column=?");$stmt->execute([$id]);if((int)$stmt->fetchColumn()>0)throw new DomainException('Não é possível remover um setor que está a ser utilizado.');}
                $pdo->prepare('DELETE FROM erp_work_centers WHERE id=?')->execute([$id]);erp_audit($pdo,$userId,'delete','erp_work_centers',$id,[],[]);$flashSuccess='Setor de operações removido com sucesso.';
            } else {
                $allowNegativeStock = isset($_POST['allow_negative_stock']) && $_POST['allow_negative_stock'] === '1';
                $codePattern = trim((string) ($_POST['raw_material_code_pattern'] ?? ''));
                $laborHourlyRateInput = str_replace(',', '.', trim((string) ($_POST['labor_hourly_rate'] ?? '')));
                $laborHourlyRate = is_numeric($laborHourlyRateInput) ? (float) $laborHourlyRateInput : -1;
                $biSettings = [];
                foreach (['bi_tv_refresh_seconds','bi_tv_rotate_seconds','bi_target_deadline_percent','bi_warning_deadline_percent','bi_target_waste_percent','bi_warning_waste_percent'] as $biKey) $biSettings[$biKey]=(float)str_replace(',','.',(string)($_POST[$biKey]??'0'));
                if ($codePattern === '' || strpos($codePattern, '{seq}') === false) throw new InvalidArgumentException('O padrão de código das matérias-primas deve incluir {seq}.');
                if ($laborHourlyRate < 0) throw new InvalidArgumentException('Indique um valor de mão de obra por hora válido.');
                if ($biSettings['bi_tv_refresh_seconds']<30||$biSettings['bi_tv_rotate_seconds']<30||$biSettings['bi_target_deadline_percent']<$biSettings['bi_warning_deadline_percent']||$biSettings['bi_warning_waste_percent']<$biSettings['bi_target_waste_percent']) throw new InvalidArgumentException('Reveja os limites do BI: atualização e deslocamento mínimos de 30 s e limites de aviso coerentes.');
                $pdo->beginTransaction();$saveSetting=$pdo->prepare('INSERT INTO erp_settings(key,value,updated_by,updated_at) VALUES (?,?,?,CURRENT_TIMESTAMP) ON CONFLICT(key) DO UPDATE SET value=excluded.value,updated_by=excluded.updated_by,updated_at=CURRENT_TIMESTAMP');
                $saveSetting->execute(['allow_negative_stock',$allowNegativeStock?'1':'0',$userId]);$saveSetting->execute(['raw_material_code_pattern',$codePattern,$userId]);$saveSetting->execute(['labor_hourly_rate',number_format($laborHourlyRate,2,'.',''),$userId]);foreach($biSettings as $key=>$value)$saveSetting->execute([$key,(string)$value,$userId]);
                $saveSequence=$pdo->prepare('UPDATE erp_number_sequences SET prefix=?,next_number=?,padding=?,suffix=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');foreach((array)($_POST['sequence_id']??[]) as $index=>$rawId)$saveSequence->execute([trim((string)($_POST['sequence_prefix'][$index]??'')),max(1,(int)($_POST['sequence_next_number'][$index]??1)),min(12,max(1,(int)($_POST['sequence_padding'][$index]??5))),trim((string)($_POST['sequence_suffix'][$index]??'')),(int)$rawId]);
                erp_audit($pdo,$userId,'update','erp_settings',null,[],['allow_negative_stock'=>$allowNegativeStock,'raw_material_code_pattern'=>$codePattern,'labor_hourly_rate'=>$laborHourlyRate,'business_intelligence'=>$biSettings]);$pdo->commit();$flashSuccess='Configuração do ERP atualizada com sucesso.';
            }
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $flashError = $exception instanceof PDOException ? 'Não foi possível guardar: o código ou nome já existe.' : $exception->getMessage();
        }
    }
}
$settings = $pdo->query('SELECT key, value FROM erp_settings')->fetchAll(PDO::FETCH_KEY_PAIR);
$sequences = $pdo->query('SELECT id, code, prefix, next_number, padding, suffix FROM erp_number_sequences ORDER BY code')->fetchAll(PDO::FETCH_ASSOC);
$operationTypes = $pdo->query('SELECT id,code,name FROM erp_operation_types ORDER BY name COLLATE NOCASE')->fetchAll(PDO::FETCH_ASSOC);
$operationSectors = $pdo->query('SELECT id,code,name,hourly_rate,is_active FROM erp_work_centers ORDER BY is_active DESC,code COLLATE NOCASE')->fetchAll(PDO::FETCH_ASSOC);
$sequenceLabels = [
    'customer' => 'Clientes',
    'finished_product' => 'Produtos acabados',
    'raw_material' => 'Matérias-primas',
    'subsidiary' => 'Produtos subsidiários',
    'consumable' => 'Produtos consumíveis',
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

<div class="row g-4 mb-4">
    <div class="col-xl-6">
        <section class="card shadow-sm soft-card h-100" aria-labelledby="operation-types-title"><div class="card-body p-4">
            <h2 class="h5" id="operation-types-title">Tipos de operações</h2><p class="text-muted">Adicione, edite ou remova as opções apresentadas no campo Tipo das operações.</p>
            <?php foreach($operationTypes as $type): ?><form method="post" class="row g-2 align-items-center mb-2"><?=csrf_input()?><input type="hidden" name="action" value="save_operation_type"><input type="hidden" name="id" value="<?=(int)$type['id']?>"><div class="col-4"><input class="form-control" name="code" required value="<?=h($type['code'])?>" aria-label="Código do tipo"></div><div class="col"><input class="form-control" name="name" required value="<?=h($type['name'])?>" aria-label="Nome do tipo"></div><div class="col-auto"><button class="btn btn-outline-primary" aria-label="Guardar tipo"><i class="bi bi-check-lg"></i></button><button class="btn btn-outline-danger" name="action" value="delete_operation_type" formnovalidate aria-label="Remover tipo" onclick="return confirm('Remover este tipo de operação?')"><i class="bi bi-trash"></i></button></div></form><?php endforeach; ?>
            <form method="post" class="row g-2 align-items-center mt-3 pt-3 border-top"><?=csrf_input()?><input type="hidden" name="action" value="save_operation_type"><div class="col-4"><input class="form-control" name="code" required placeholder="Código"></div><div class="col"><input class="form-control" name="name" required placeholder="Novo tipo"></div><div class="col-auto"><button class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Adicionar</button></div></form>
        </div></section>
    </div>
    <div class="col-xl-6">
        <section class="card shadow-sm soft-card h-100" aria-labelledby="operation-sectors-title"><div class="card-body p-4">
            <h2 class="h5" id="operation-sectors-title">Setores de operações</h2><p class="text-muted">Gira os setores disponíveis para associar às operações.</p>
            <?php foreach($operationSectors as $sector): ?><form method="post" class="row g-2 align-items-center mb-2"><?=csrf_input()?><input type="hidden" name="action" value="save_operation_sector"><input type="hidden" name="id" value="<?=(int)$sector['id']?>"><div class="col-3"><input class="form-control" name="code" required value="<?=h($sector['code'])?>" aria-label="Código do setor"></div><div class="col"><input class="form-control" name="name" required value="<?=h($sector['name'])?>" aria-label="Nome do setor"></div><div class="col-2"><input class="form-control" type="number" min="0" step="0.01" name="hourly_rate" value="<?=h((string)$sector['hourly_rate'])?>" aria-label="Custo por hora"></div><div class="col-auto"><input type="hidden" name="is_active" value="0"><input class="form-check-input me-2" type="checkbox" name="is_active" value="1" <?=(int)$sector['is_active']?'checked':''?> aria-label="Setor ativo"><button class="btn btn-outline-primary" aria-label="Guardar setor"><i class="bi bi-check-lg"></i></button><button class="btn btn-outline-danger" name="action" value="delete_operation_sector" formnovalidate aria-label="Remover setor" onclick="return confirm('Remover este setor de operações?')"><i class="bi bi-trash"></i></button></div></form><?php endforeach; ?>
            <form method="post" class="row g-2 align-items-center mt-3 pt-3 border-top"><?=csrf_input()?><input type="hidden" name="action" value="save_operation_sector"><input type="hidden" name="is_active" value="1"><div class="col-3"><input class="form-control" name="code" required placeholder="Código"></div><div class="col"><input class="form-control" name="name" required placeholder="Novo setor"></div><div class="col-2"><input class="form-control" type="number" min="0" step="0.01" name="hourly_rate" value="0" aria-label="Custo por hora"></div><div class="col-auto"><button class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Adicionar</button></div></form>
        </div></section>
    </div>
</div>

<form method="post" class="card shadow-sm soft-card">
    <?= csrf_input() ?>
    <input type="hidden" name="action" value="save_settings">
    <div class="card-body p-4">
        <h2 class="h5">Regras de stock e codificação</h2>
        <div class="row g-3 align-items-end">
            <div class="col-lg-6">
                <label class="form-label" for="raw-material-code-pattern">Padrão do código de matéria-prima</label>
                <input class="form-control" id="raw-material-code-pattern" name="raw_material_code_pattern" required value="<?= h((string) ($settings['raw_material_code_pattern'] ?? '{tipo}{caracteristica}{largura}{gramagem}{seq}')) ?>">
                <div class="form-text">Marcadores disponíveis: {tipo}, {caracteristica}, {largura}, {gramagem} e {seq}. O marcador {seq} é obrigatório.</div>
            </div>
            <div class="col-lg-3">
                <label class="form-label" for="labor-hourly-rate">Mão de obra por hora</label>
                <div class="input-group">
                    <input class="form-control" type="number" min="0" step="0.01" id="labor-hourly-rate" name="labor_hourly_rate" required value="<?= h(number_format((float) ($settings['labor_hourly_rate'] ?? 0), 2, '.', '')) ?>">
                    <span class="input-group-text">€/h</span>
                </div>
                <div class="form-text">Valor base aplicado ao cálculo dos custos de mão de obra.</div>
            </div>
            <div class="col-lg-3 pb-2">
                <input type="hidden" name="allow_negative_stock" value="0">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" id="allow-negative-stock" name="allow_negative_stock" value="1" <?= ($settings['allow_negative_stock'] ?? '0') === '1' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="allow-negative-stock">Permitir movimentos que originem stock negativo</label>
                </div>
            </div>
        </div>

        <hr class="my-4">
        <h2 class="h5">Business Intelligence e modo TV</h2>
        <p class="small text-muted">Os limites determinam automaticamente as cores verde, amarela e vermelha. Não são atribuídas cores aleatórias.</p>
        <div class="row g-3">
            <?php $biFields=['bi_tv_refresh_seconds'=>['Atualizar a cada','segundos',30],'bi_tv_rotate_seconds'=>['Deslocar ecrã a cada','segundos',30],'bi_target_deadline_percent'=>['Prazo: meta verde','%',0],'bi_warning_deadline_percent'=>['Prazo: mínimo amarelo','%',0],'bi_target_waste_percent'=>['Desperdício: máximo verde','%',0],'bi_warning_waste_percent'=>['Desperdício: máximo amarelo','%',0]]; foreach($biFields as $key=>$meta): ?>
            <div class="col-md-4 col-xl-2"><label class="form-label" for="<?=h($key)?>"><?=h($meta[0])?></label><div class="input-group"><input class="form-control" id="<?=h($key)?>" name="<?=h($key)?>" type="number" step="1" min="<?=$meta[2]?>" required value="<?=h((string)($settings[$key]??0))?>"><span class="input-group-text"><?=h($meta[1])?></span></div></div>
            <?php endforeach; ?>
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
