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
            if ($action === 'save_material_type') {
                $id=(int)($_POST['id']??0);$code=strtoupper(trim((string)($_POST['code']??'')));$name=trim((string)($_POST['name']??''));
                if($code===''||$name==='')throw new InvalidArgumentException('O código e o nome do tipo de material são obrigatórios.');
                if($id){$exists=$pdo->prepare('SELECT 1 FROM erp_material_types WHERE id=?');$exists->execute([$id]);if(!$exists->fetchColumn())throw new InvalidArgumentException('Tipo de material inexistente.');$pdo->prepare('UPDATE erp_material_types SET code=?,name=?,is_active=? WHERE id=?')->execute([$code,$name,!empty($_POST['is_active'])?1:0,$id]);}
                else{$pdo->prepare('INSERT INTO erp_material_types(code,name,is_active) VALUES (?,?,?)')->execute([$code,$name,!empty($_POST['is_active'])?1:0]);$id=(int)$pdo->lastInsertId();}
                erp_audit($pdo,$userId,(int)($_POST['id']??0)?'update':'create','erp_material_types',$id,[],['code'=>$code,'name'=>$name]);$flashSuccess='Tipo de material guardado com sucesso.';
            } elseif ($action === 'save_ink_type') {
                $id=(int)($_POST['id']??0);$code=strtoupper(trim((string)($_POST['code']??'')));$name=trim((string)($_POST['name']??''));$icon=trim((string)($_POST['icon']??''));
                $allowedIcons=['bi-droplet-fill','bi-bucket-fill','bi-paint-bucket','bi-palette-fill','bi-circle-fill','bi-water'];
                if($code===''||$name===''||!in_array($icon,$allowedIcons,true))throw new InvalidArgumentException('Indique o código, o nome e um ícone válido para o tipo de tinta.');
                $values=[$code,$name,$icon,!empty($_POST['is_active'])?1:0];
                if($id){$exists=$pdo->prepare('SELECT 1 FROM erp_ink_types WHERE id=?');$exists->execute([$id]);if(!$exists->fetchColumn())throw new InvalidArgumentException('Tipo de tinta inexistente.');$pdo->prepare('UPDATE erp_ink_types SET code=?,name=?,icon=?,is_active=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute(array_merge($values,[$id]));}
                else{$pdo->prepare('INSERT INTO erp_ink_types(code,name,icon,is_active) VALUES (?,?,?,?)')->execute($values);$id=(int)$pdo->lastInsertId();}
                erp_audit($pdo,$userId,(int)($_POST['id']??0)?'update':'create','erp_ink_types',$id,[],['code'=>$code,'name'=>$name,'icon'=>$icon]);$flashSuccess='Tipo de tinta guardado com sucesso.';
            } elseif ($action === 'delete_ink_type') {
                $id=(int)($_POST['id']??0);$used=$pdo->prepare('SELECT COUNT(*) FROM erp_raw_materials WHERE ink_type_id=?');$used->execute([$id]);if((int)$used->fetchColumn())throw new DomainException('Não é possível remover um tipo de tinta que está a ser utilizado.');
                $pdo->prepare('DELETE FROM erp_ink_types WHERE id=?')->execute([$id]);erp_audit($pdo,$userId,'delete','erp_ink_types',$id,[],[]);$flashSuccess='Tipo de tinta removido com sucesso.';
            } elseif ($action === 'save_document_control') {
                $id = (int) ($_POST['id'] ?? 0);
                $documentNumber = strtoupper(trim((string) ($_POST['document_number'] ?? '')));
                if ($id < 1 || $documentNumber === '') throw new InvalidArgumentException('Indique o número de controlo do documento.');
                if (strlen($documentNumber) > 50) throw new InvalidArgumentException('O número do documento não pode exceder 50 caracteres.');
                $existing = $pdo->prepare('SELECT document_number,is_active FROM erp_document_catalog WHERE id=?');
                $existing->execute([$id]); $oldDocument = $existing->fetch(PDO::FETCH_ASSOC);
                if (!$oldDocument) throw new InvalidArgumentException('Documento inexistente no catálogo.');
                $duplicate = $pdo->prepare('SELECT 1 FROM erp_document_catalog WHERE document_number=? AND id<>?');
                $duplicate->execute([$documentNumber,$id]);
                if ($duplicate->fetchColumn()) throw new InvalidArgumentException('Este número de documento já está atribuído a outro registo.');
                $active = !empty($_POST['is_active']) ? 1 : 0;
                $pdo->prepare('UPDATE erp_document_catalog SET document_number=?,is_active=?,updated_by=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$documentNumber,$active,$userId,$id]);
                erp_audit($pdo,$userId,'update','erp_document_catalog',$id,$oldDocument,['document_number'=>$documentNumber,'is_active'=>$active]);
                $flashSuccess = 'Controlo documental atualizado com sucesso.';
            } elseif ($action === 'delete_material_type') {
                $id=(int)($_POST['id']??0);$used=0;foreach([['erp_raw_materials','material_type_id'],['erp_finished_products','material_type_id'],['erp_material_features','material_type_id']]as$reference){$stmt=$pdo->prepare('SELECT COUNT(*) FROM '.$reference[0].' WHERE '.$reference[1].'=?');$stmt->execute([$id]);$used+=(int)$stmt->fetchColumn();}if($used)throw new DomainException('Não é possível remover um tipo de material que está a ser utilizado.');
                $pdo->prepare('DELETE FROM erp_material_types WHERE id=?')->execute([$id]);erp_audit($pdo,$userId,'delete','erp_material_types',$id,[],[]);$flashSuccess='Tipo de material removido com sucesso.';
            } elseif ($action === 'save_operation_type') {
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
            } elseif ($action === 'save_printer') {
                $id=(int)($_POST['id']??0);$name=trim((string)($_POST['name']??''));$uri=trim((string)($_POST['network_uri']??''));
                if($name===''||!preg_match('#^(ipp|ipps|lpd|socket|smb)://[^\s]+$#i',$uri))throw new InvalidArgumentException('Indique um nome e um endereço de rede válido (IPP, IPPS, LPD, socket ou SMB).');
                $values=[$name,$uri,trim((string)($_POST['location']??'')),trim((string)($_POST['driver_name']??'')),!empty($_POST['is_active'])?1:0];
                if($id){$pdo->prepare('UPDATE erp_printers SET name=?,network_uri=?,location=?,driver_name=?,is_active=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute(array_merge($values,[$id]));}
                else{$pdo->prepare('INSERT INTO erp_printers(name,network_uri,location,driver_name,is_active) VALUES (?,?,?,?,?)')->execute($values);$id=(int)$pdo->lastInsertId();}
                erp_audit($pdo,$userId,(int)($_POST['id']??0)?'update':'create','erp_printers',$id,[],['name'=>$name,'network_uri'=>$uri]);$flashSuccess='Impressora de rede guardada com sucesso.';
            } elseif ($action === 'delete_printer') {
                $id=(int)($_POST['id']??0);$used=$pdo->prepare('SELECT COUNT(*) FROM erp_work_centers WHERE default_printer_id=?');$used->execute([$id]);if((int)$used->fetchColumn())throw new DomainException('Não é possível remover uma impressora associada a um centro de trabalho.');
                $pdo->prepare('DELETE FROM erp_printers WHERE id=?')->execute([$id]);erp_audit($pdo,$userId,'delete','erp_printers',$id,[],[]);$flashSuccess='Impressora removida com sucesso.';
            } elseif ($action === 'save_operation_sector') {
                $id=(int)($_POST['id']??0);$code=strtoupper(trim((string)($_POST['code']??'')));$name=trim((string)($_POST['name']??''));
                $centerType=(string)($_POST['center_type']??'administrative');$machineId=(int)($_POST['machine_id']??0);$printerId=(int)($_POST['default_printer_id']??0);$capacity=(int)($_POST['daily_capacity_minutes']??480);$efficiency=(float)str_replace(',','.',(string)($_POST['efficiency_percent']??100));
                if ($code===''||$name==='') throw new InvalidArgumentException('O código e o nome do centro são obrigatórios.');
                if(!in_array($centerType,['administrative','machine'],true))throw new InvalidArgumentException('Selecione um tipo de centro válido.');
                if($centerType==='machine'&&!$machineId)throw new InvalidArgumentException('Um centro do tipo máquina exige a seleção da máquina utilizada.');
                if($centerType==='administrative')$machineId=0;if($capacity<=0||$efficiency<=0||$efficiency>100)throw new InvalidArgumentException('A capacidade deve ser positiva e a eficiência deve estar entre 0 e 100%.');
                $values=[$code,$name,max(0,(float)str_replace(',','.',(string)($_POST['hourly_rate']??0))),$centerType,$machineId?:null,$printerId?:null,$capacity,$efficiency,!empty($_POST['is_active'])?1:0];
                if($id){$pdo->prepare('UPDATE erp_work_centers SET code=?,name=?,hourly_rate=?,center_type=?,machine_id=?,default_printer_id=?,daily_capacity_minutes=?,efficiency_percent=?,is_active=? WHERE id=?')->execute(array_merge($values,[$id]));}
                else{$pdo->prepare('INSERT INTO erp_work_centers(code,name,hourly_rate,center_type,machine_id,default_printer_id,daily_capacity_minutes,efficiency_percent,is_active) VALUES (?,?,?,?,?,?,?,?,?)')->execute($values);$id=(int)$pdo->lastInsertId();}
                erp_audit($pdo,$userId,(int)($_POST['id']??0)?'update':'create','erp_work_centers',$id,[],['code'=>$code,'name'=>$name]);$flashSuccess='Setor de operações guardado com sucesso.';
            } elseif ($action === 'delete_operation_sector') {
                $id=(int)($_POST['id']??0);$references=['erp_operations'=>'default_work_center_id','erp_machines'=>'work_center_id','erp_article_routing_steps'=>'work_center_id','erp_shift_assignments'=>'work_center_id'];
                foreach($references as $table=>$column){$stmt=$pdo->prepare("SELECT COUNT(*) FROM $table WHERE $column=?");$stmt->execute([$id]);if((int)$stmt->fetchColumn()>0)throw new DomainException('Não é possível remover um setor que está a ser utilizado.');}
                $pdo->prepare('DELETE FROM erp_work_centers WHERE id=?')->execute([$id]);erp_audit($pdo,$userId,'delete','erp_work_centers',$id,[],[]);$flashSuccess='Setor de operações removido com sucesso.';
            } elseif ($action === 'reset_work_order_sequence') {
                $nextNumber = filter_var($_POST['work_order_next_number'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
                if ($nextNumber === false) throw new InvalidArgumentException('Indique um número válido para a próxima OF.');
                $sequence = $pdo->query("SELECT id,prefix,next_number,padding,suffix FROM erp_number_sequences WHERE code='work_order'")->fetch(PDO::FETCH_ASSOC);
                if (!$sequence) throw new RuntimeException('A sequência das ordens de fabrico não está configurada.');
                $pdo->prepare('UPDATE erp_number_sequences SET next_number=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$nextNumber,(int)$sequence['id']]);
                erp_audit($pdo,$userId,'reset_sequence','erp_number_sequences',(int)$sequence['id'],['next_number'=>(int)$sequence['next_number']],['next_number'=>$nextNumber]);
                $flashSuccess='A próxima Ordem de Fabrico será a n.º '.(string)$sequence['prefix'].str_pad((string)$nextNumber,(int)$sequence['padding'],'0',STR_PAD_LEFT).(string)($sequence['suffix']??'').'.';
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
$workOrderSequence = null;
foreach ($sequences as $sequence) if ($sequence['code'] === 'work_order') $workOrderSequence = $sequence;
$operationTypes = $pdo->query('SELECT id,code,name FROM erp_operation_types ORDER BY name COLLATE NOCASE')->fetchAll(PDO::FETCH_ASSOC);
$operationSectors = $pdo->query('SELECT wc.*,m.code machine_code,p.name printer_name FROM erp_work_centers wc LEFT JOIN erp_machines m ON m.id=wc.machine_id LEFT JOIN erp_printers p ON p.id=wc.default_printer_id ORDER BY wc.is_active DESC,wc.code COLLATE NOCASE')->fetchAll(PDO::FETCH_ASSOC);
$machines = $pdo->query('SELECT id,code,name FROM erp_machines WHERE is_active=1 AND deleted_at IS NULL ORDER BY code')->fetchAll(PDO::FETCH_ASSOC);
$printers = $pdo->query('SELECT * FROM erp_printers ORDER BY is_active DESC,name COLLATE NOCASE')->fetchAll(PDO::FETCH_ASSOC);
$materialTypes = $pdo->query('SELECT id,code,name,is_active FROM erp_material_types ORDER BY is_active DESC,name COLLATE NOCASE')->fetchAll(PDO::FETCH_ASSOC);
$inkTypes = $pdo->query('SELECT id,code,name,icon,is_active FROM erp_ink_types ORDER BY is_active DESC,name COLLATE NOCASE')->fetchAll(PDO::FETCH_ASSOC);
$controlledDocuments = $pdo->query('SELECT id,code,document_number,name,module,output_format,generation_route,is_active,updated_at FROM erp_document_catalog ORDER BY module COLLATE NOCASE,document_number COLLATE NOCASE')->fetchAll(PDO::FETCH_ASSOC);
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

<section class="card shadow-sm soft-card mb-4" aria-labelledby="document-control-title">
    <div class="card-body p-4">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
            <div><h2 class="h5 mb-1" id="document-control-title"><i class="bi bi-journal-check me-2 text-primary"></i>Controlo documental ISO 9001</h2><p class="text-muted mb-0">Listagem central de todos os modelos e documentos gerados pelo sistema. Cada tipo possui um número de controlo único e rastreável.</p></div>
            <span class="badge text-bg-primary rounded-pill"><?= count($controlledDocuments) ?> documentos</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>N.º do documento</th><th>Documento</th><th>Módulo</th><th>Formato</th><th>Origem no sistema</th><th>Estado</th><th class="text-end">Ação</th></tr></thead>
                <tbody><?php foreach ($controlledDocuments as $document): ?>
                    <tr>
                        <td><form method="post" id="document-control-<?= (int)$document['id'] ?>"><?= csrf_input() ?><input type="hidden" name="action" value="save_document_control"><input type="hidden" name="id" value="<?= (int)$document['id'] ?>"></form><input class="form-control font-monospace" form="document-control-<?= (int)$document['id'] ?>" name="document_number" maxlength="50" required value="<?= h($document['document_number']) ?>" aria-label="Número de <?= h($document['name']) ?>"></td>
                        <td><strong><?= h($document['name']) ?></strong><small class="d-block text-muted font-monospace"><?= h($document['code']) ?></small></td>
                        <td><?= h($document['module']) ?></td><td><span class="badge text-bg-light border"><?= h($document['output_format']) ?></span></td>
                        <td><code class="small"><?= h($document['generation_route']) ?></code></td>
                        <td><input type="hidden" form="document-control-<?= (int)$document['id'] ?>" name="is_active" value="0"><div class="form-check form-switch"><input class="form-check-input" form="document-control-<?= (int)$document['id'] ?>" type="checkbox" name="is_active" value="1" <?= $document['is_active'] ? 'checked' : '' ?> aria-label="Documento ativo"></div></td>
                        <td class="text-end"><button class="btn btn-sm btn-outline-primary" form="document-control-<?= (int)$document['id'] ?>"><i class="bi bi-check-lg me-1"></i>Guardar</button></td>
                    </tr>
                <?php endforeach; ?></tbody>
            </table>
        </div>
        <p class="small text-muted mt-3 mb-0"><i class="bi bi-info-circle me-1"></i>O número identifica o tipo/modelo documental; os números transacionais (por exemplo, OF e movimentos) continuam a ser geridos nas sequências abaixo. As alterações ficam registadas na auditoria.</p>
    </div>
</section>

<div class="row g-4 mb-4">
    <div class="col-12">
        <section class="card shadow-sm soft-card" aria-labelledby="material-types-title"><div class="card-body p-4">
            <h2 class="h5" id="material-types-title">Tipos de material</h2><p class="text-muted">Crie e edite os tipos usados nos artigos. Só é possível eliminar tipos sem artigos ou características associados.</p>
            <?php foreach($materialTypes as$type):?><form method="post" class="row g-2 align-items-center mb-2"><?=csrf_input()?><input type="hidden" name="action" value="save_material_type"><input type="hidden" name="id" value="<?=(int)$type['id']?>"><div class="col-md-3"><input class="form-control" name="code" required value="<?=h($type['code'])?>" aria-label="Código do tipo de material"></div><div class="col"><input class="form-control" name="name" required value="<?=h($type['name'])?>" aria-label="Nome do tipo de material"></div><div class="col-auto"><input type="hidden" name="is_active" value="0"><label class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="is_active" value="1" <?=$type['is_active']?'checked':''?>> Ativo</label><button class="btn btn-outline-primary" aria-label="Guardar tipo de material"><i class="bi bi-check-lg"></i></button> <button class="btn btn-outline-danger" name="action" value="delete_material_type" formnovalidate aria-label="Remover tipo de material" onclick="return confirm('Remover este tipo de material?')"><i class="bi bi-trash"></i></button></div></form><?php endforeach;?>
            <form method="post" class="row g-2 align-items-center mt-3 pt-3 border-top"><?=csrf_input()?><input type="hidden" name="action" value="save_material_type"><input type="hidden" name="is_active" value="1"><div class="col-md-3"><input class="form-control" name="code" required placeholder="Código"></div><div class="col"><input class="form-control" name="name" required placeholder="Novo tipo de material"></div><div class="col-auto"><button class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Adicionar</button></div></form>
        </div></section>
    </div>
    <div class="col-12">
        <section class="card shadow-sm soft-card" aria-labelledby="ink-types-title"><div class="card-body p-4">
            <h2 class="h5" id="ink-types-title">Tipos de tinta</h2><p class="text-muted">Configure os tipos disponíveis e o ícone apresentado antes do nome da cor.</p>
            <?php $inkIconOptions=['bi-droplet-fill'=>'Gota','bi-bucket-fill'=>'Balde','bi-paint-bucket'=>'Balde de tinta','bi-palette-fill'=>'Paleta','bi-circle-fill'=>'Círculo','bi-water'=>'Água'];foreach($inkTypes as$type):?><form method="post" class="row g-2 align-items-center mb-2"><?=csrf_input()?><input type="hidden" name="action" value="save_ink_type"><input type="hidden" name="id" value="<?=(int)$type['id']?>"><div class="col-md-2"><input class="form-control" name="code" required value="<?=h($type['code'])?>" aria-label="Código do tipo de tinta"></div><div class="col-md-3"><input class="form-control" name="name" required value="<?=h($type['name'])?>" aria-label="Nome do tipo de tinta"></div><div class="col-md-3"><select class="form-select" name="icon" aria-label="Ícone do tipo de tinta"><?php foreach($inkIconOptions as$icon=>$label):?><option value="<?=h($icon)?>" <?=$type['icon']===$icon?'selected':''?>><?=h($label)?></option><?php endforeach;?></select></div><div class="col-auto"><i class="bi <?=h($type['icon'])?> fs-5 me-2" title="<?=h($type['name'])?>"></i><input type="hidden" name="is_active" value="0"><label class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="is_active" value="1" <?=$type['is_active']?'checked':''?>> Ativo</label><button class="btn btn-outline-primary" aria-label="Guardar tipo de tinta"><i class="bi bi-check-lg"></i></button> <button class="btn btn-outline-danger" name="action" value="delete_ink_type" formnovalidate aria-label="Remover tipo de tinta" onclick="return confirm('Remover este tipo de tinta?')"><i class="bi bi-trash"></i></button></div></form><?php endforeach;?>
            <form method="post" class="row g-2 align-items-center mt-3 pt-3 border-top"><?=csrf_input()?><input type="hidden" name="action" value="save_ink_type"><input type="hidden" name="is_active" value="1"><div class="col-md-2"><input class="form-control" name="code" required placeholder="Código"></div><div class="col-md-3"><input class="form-control" name="name" required placeholder="Novo tipo de tinta"></div><div class="col-md-3"><select class="form-select" name="icon"><?php foreach($inkIconOptions as$icon=>$label):?><option value="<?=h($icon)?>"><?=h($label)?></option><?php endforeach;?></select></div><div class="col-auto"><button class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Adicionar</button></div></form>
        </div></section>
    </div>
    <div class="col-xl-6">
        <section class="card shadow-sm soft-card h-100" aria-labelledby="operation-types-title"><div class="card-body p-4">
            <h2 class="h5" id="operation-types-title">Tipos de operações</h2><p class="text-muted">Adicione, edite ou remova as opções apresentadas no campo Tipo das operações.</p>
            <?php foreach($operationTypes as $type): ?><form method="post" class="row g-2 align-items-center mb-2"><?=csrf_input()?><input type="hidden" name="action" value="save_operation_type"><input type="hidden" name="id" value="<?=(int)$type['id']?>"><div class="col-4"><input class="form-control" name="code" required value="<?=h($type['code'])?>" aria-label="Código do tipo"></div><div class="col"><input class="form-control" name="name" required value="<?=h($type['name'])?>" aria-label="Nome do tipo"></div><div class="col-auto"><button class="btn btn-outline-primary" aria-label="Guardar tipo"><i class="bi bi-check-lg"></i></button><button class="btn btn-outline-danger" name="action" value="delete_operation_type" formnovalidate aria-label="Remover tipo" onclick="return confirm('Remover este tipo de operação?')"><i class="bi bi-trash"></i></button></div></form><?php endforeach; ?>
            <form method="post" class="row g-2 align-items-center mt-3 pt-3 border-top"><?=csrf_input()?><input type="hidden" name="action" value="save_operation_type"><div class="col-4"><input class="form-control" name="code" required placeholder="Código"></div><div class="col"><input class="form-control" name="name" required placeholder="Novo tipo"></div><div class="col-auto"><button class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Adicionar</button></div></form>
        </div></section>
    </div>
    <div class="col-12">
        <section class="card shadow-sm soft-card" aria-labelledby="work-centers-title"><div class="card-body p-4">
            <h2 class="h5" id="work-centers-title">Centros de trabalho e capacidade</h2>
            <p class="text-muted">Defina se o posto é administrativo ou utiliza uma máquina. A capacidade líquida alimenta automaticamente a previsão das operações.</p>
            <?php foreach($operationSectors as $sector): ?>
            <form method="post" class="row g-2 align-items-end mb-3 pb-3 border-bottom"><?=csrf_input()?><input type="hidden" name="action" value="save_operation_sector"><input type="hidden" name="id" value="<?=(int)$sector['id']?>">
                <div class="col-md-2"><label class="form-label">Código</label><input class="form-control" name="code" required value="<?=h($sector['code'])?>"></div>
                <div class="col-md-3"><label class="form-label">Nome</label><input class="form-control" name="name" required value="<?=h($sector['name'])?>"></div>
                <div class="col-md-2"><label class="form-label">Tipo</label><select class="form-select js-center-type" name="center_type"><option value="administrative" <?=$sector['center_type']==='administrative'?'selected':''?>>Administrativo</option><option value="machine" <?=$sector['center_type']==='machine'?'selected':''?>>Máquina</option></select></div>
                <div class="col-md-3 js-center-machine"><label class="form-label">Máquina utilizada</label><select class="form-select" name="machine_id"><option value="">Selecione…</option><?php foreach($machines as$m):?><option value="<?=$m['id']?>" <?=(int)$sector['machine_id']===(int)$m['id']?'selected':''?>><?=h($m['code'].' · '.$m['name'])?></option><?php endforeach;?></select></div>
                <div class="col-md-2"><label class="form-label">Impressora</label><select class="form-select" name="default_printer_id"><option value="">Sem impressora</option><?php foreach($printers as$p):if(!$p['is_active']&&(int)$sector['default_printer_id']!==(int)$p['id'])continue;?><option value="<?=$p['id']?>" <?=(int)$sector['default_printer_id']===(int)$p['id']?'selected':''?>><?=h($p['name'])?></option><?php endforeach;?></select></div>
                <div class="col-md-2"><label class="form-label">Capacidade/dia</label><div class="input-group"><input class="form-control" type="number" min="1" name="daily_capacity_minutes" value="<?=(int)$sector['daily_capacity_minutes']?>"><span class="input-group-text">min</span></div></div>
                <div class="col-md-2"><label class="form-label">Eficiência</label><div class="input-group"><input class="form-control" type="number" min="1" max="100" step="0.1" name="efficiency_percent" value="<?=h((string)$sector['efficiency_percent'])?>"><span class="input-group-text">%</span></div></div>
                <div class="col-md-2"><label class="form-label">Custo/hora</label><input class="form-control" type="number" min="0" step="0.01" name="hourly_rate" value="<?=h((string)$sector['hourly_rate'])?>"></div>
                <div class="col"><input type="hidden" name="is_active" value="0"><label class="form-check d-inline-block me-2"><input class="form-check-input" type="checkbox" name="is_active" value="1" <?=$sector['is_active']?'checked':''?>> Ativo</label><button class="btn btn-outline-primary"><i class="bi bi-check-lg"></i> Guardar</button> <button class="btn btn-outline-danger" name="action" value="delete_operation_sector" formnovalidate onclick="return confirm('Remover este centro de trabalho?')"><i class="bi bi-trash"></i></button></div>
            </form><?php endforeach; ?>
            <form method="post" class="row g-2 align-items-end"><?=csrf_input()?><input type="hidden" name="action" value="save_operation_sector"><input type="hidden" name="is_active" value="1"><div class="col-md-2"><label class="form-label">Código</label><input class="form-control" name="code" required></div><div class="col-md-3"><label class="form-label">Novo centro</label><input class="form-control" name="name" required></div><div class="col-md-2"><label class="form-label">Tipo</label><select class="form-select js-center-type" name="center_type"><option value="administrative">Administrativo</option><option value="machine">Máquina</option></select></div><div class="col-md-3 js-center-machine"><label class="form-label">Máquina utilizada</label><select class="form-select" name="machine_id"><option value="">Selecione…</option><?php foreach($machines as$m):?><option value="<?=$m['id']?>"><?=h($m['code'].' · '.$m['name'])?></option><?php endforeach;?></select></div><div class="col-md-2"><label class="form-label">Impressora</label><select class="form-select" name="default_printer_id"><option value="">Sem impressora</option><?php foreach($printers as$p):if(!$p['is_active'])continue;?><option value="<?=$p['id']?>"><?=h($p['name'])?></option><?php endforeach;?></select></div><div class="col-md-2"><label class="form-label">Capacidade/dia</label><input class="form-control" type="number" min="1" name="daily_capacity_minutes" value="480"></div><div class="col-md-2"><label class="form-label">Eficiência %</label><input class="form-control" type="number" min="1" max="100" name="efficiency_percent" value="100"></div><div class="col-md-2"><label class="form-label">Custo/hora</label><input class="form-control" type="number" min="0" step=".01" name="hourly_rate" value="0"></div><div class="col"><button class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Adicionar centro</button></div></form>
        </div></section>
    </div>
    <div class="col-12">
        <section class="card shadow-sm soft-card" aria-labelledby="printers-title"><div class="card-body p-4"><h2 class="h5" id="printers-title">Impressoras de rede</h2><p class="text-muted">Configure os destinos disponíveis para documentos e etiquetas em cada posto.</p>
        <?php foreach($printers as$p):?><form method="post" class="row g-2 align-items-end mb-2"><?=csrf_input()?><input type="hidden" name="action" value="save_printer"><input type="hidden" name="id" value="<?=$p['id']?>"><div class="col-md-3"><label class="form-label">Nome</label><input class="form-control" name="name" required value="<?=h($p['name'])?>"></div><div class="col-md-3"><label class="form-label">Endereço de rede</label><input class="form-control" name="network_uri" required value="<?=h($p['network_uri'])?>"></div><div class="col-md-2"><label class="form-label">Local</label><input class="form-control" name="location" value="<?=h((string)$p['location'])?>"></div><div class="col-md-2"><label class="form-label">Controlador</label><input class="form-control" name="driver_name" value="<?=h((string)$p['driver_name'])?>"></div><div class="col"><input type="hidden" name="is_active" value="0"><label class="form-check d-inline-block"><input class="form-check-input" type="checkbox" name="is_active" value="1" <?=$p['is_active']?'checked':''?>> Ativa</label><button class="btn btn-outline-primary"><i class="bi bi-check-lg"></i></button> <button class="btn btn-outline-danger" name="action" value="delete_printer" formnovalidate><i class="bi bi-trash"></i></button></div></form><?php endforeach;?>
        <form method="post" class="row g-2 align-items-end mt-3 pt-3 border-top"><?=csrf_input()?><input type="hidden" name="action" value="save_printer"><input type="hidden" name="is_active" value="1"><div class="col-md-3"><label class="form-label">Nome</label><input class="form-control" name="name" required placeholder="Etiquetas produção"></div><div class="col-md-3"><label class="form-label">Endereço de rede</label><input class="form-control" name="network_uri" required placeholder="ipp://192.168.1.20/ipp/print"></div><div class="col-md-2"><label class="form-label">Local</label><input class="form-control" name="location"></div><div class="col-md-2"><label class="form-label">Controlador</label><input class="form-control" name="driver_name"></div><div class="col"><button class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Adicionar</button></div></form>
        </div></section>
    </div>
</div>

<?php if ($workOrderSequence): ?>
<form method="post" class="card shadow-sm soft-card border-primary mb-4">
    <?= csrf_input() ?><input type="hidden" name="action" value="reset_work_order_sequence">
    <div class="card-body p-4"><div class="row g-3 align-items-end">
        <div class="col-lg-7"><h2 class="h5 mb-1">Reiniciar numeração das Ordens de Fabrico</h2><p class="text-muted mb-0">Defina o contador para continuar a numeração já utilizada neste ano. Esta operação não elimina nem altera OF existentes.</p></div>
        <div class="col-md-3"><label class="form-label" for="work-order-next-number">N.º da próxima OF</label><input class="form-control" id="work-order-next-number" name="work_order_next_number" type="number" min="1" step="1" required value="<?= (int)$workOrderSequence['next_number'] ?>"><div class="form-text">Será criada como <strong><?=h((string)$workOrderSequence['prefix'])?><?=str_pad((string)$workOrderSequence['next_number'],(int)$workOrderSequence['padding'],'0',STR_PAD_LEFT)?><?=h((string)$workOrderSequence['suffix'])?></strong>.</div></div>
        <div class="col-md-2"><button class="btn btn-outline-primary w-100" onclick="return confirm('Confirmar o novo número da próxima OF?')"><i class="bi bi-arrow-counterclockwise me-1"></i>Reiniciar contador</button></div>
    </div></div>
</form>
<?php endif; ?>

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
<script>
document.querySelectorAll('.js-center-type').forEach(function (type) {
    function toggleMachine() {
        var field = type.closest('form').querySelector('.js-center-machine');
        if (!field) return;
        field.classList.toggle('d-none', type.value !== 'machine');
        field.querySelector('select').required = type.value === 'machine';
    }
    type.addEventListener('change', toggleMachine); toggleMachine();
});
</script>
<?php require __DIR__ . '/partials/footer.php'; ?>
