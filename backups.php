<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/app/Services/BackupManager.php';
require_admin();

$manager = new BackupManager(__DIR__);
$notice = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && validate_csrf_or_abort(false)) {
    try {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'settings') {
            $manager->saveSettings($_POST);
            $notice = 'Agendamento guardado.';
        } elseif ($action === 'create') {
            $result = $manager->create(!empty($_POST['include_files']));
            $notice = 'Backup criado: ' . $result['file'];
        } elseif ($action === 'restore_tables') {
            $result = $manager->restoreTables((string) $_POST['backup'], (array) ($_POST['tables'] ?? []));
            $notice = 'Tabelas repostas: ' . implode(', ', $result['tables']) . '. Cópia de segurança automática: ' . $result['safety_backup'];
        } elseif ($action === 'generate_token') {
            $token = bin2hex(random_bytes(24));
            if (!is_dir(__DIR__ . '/storage')) mkdir(__DIR__ . '/storage', 0750, true);
            file_put_contents(__DIR__ . '/storage/recovery.token', password_hash($token, PASSWORD_DEFAULT), LOCK_EX);
            chmod(__DIR__ . '/storage/recovery.token', 0640);
            $notice = 'Guarde já o token de recuperação (só é mostrado agora): ' . $token;
        }
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
$settings = $manager->settings();
$backups = $manager->backups();
$selectedFile = (string) ($_GET['backup'] ?? '');
$selectedManifest = [];
if ($selectedFile !== '') { try { $selectedManifest = $manager->manifest($selectedFile); } catch (Throwable $e) { $error = $e->getMessage(); } }
$pageTitle = 'Backups e recuperação';
require __DIR__ . '/partials/header.php';
?>
<div class="d-flex justify-content-between align-items-start mb-4"><div><h1 class="h3 mb-1">Backups e recuperação</h1><p class="text-muted mb-0">Cópias consistentes da base de dados e dos ficheiros da solução.</p></div><a class="btn btn-outline-danger" href="backup_recovery.php" target="_blank"><i class="bi bi-life-preserver me-1"></i>Consola independente</a></div>
<?php if ($notice): ?><div class="alert alert-success text-break"><?=h($notice)?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?=h($error)?></div><?php endif; ?>
<div class="row g-3 mb-4">
 <div class="col-lg-7"><div class="card h-100"><div class="card-body"><h2 class="h5">Agendamento</h2><p class="small text-muted">Execute <code>php cron/backup_runner.php</code> a cada minuto no cron, ou mantenha <code>php cron/backup_runner.php --daemon</code> como serviço separado.</p><form method="post" class="row g-3"><?=csrf_input()?><input type="hidden" name="action" value="settings"><div class="col-md-4"><label class="form-label">Frequência (minutos)</label><input class="form-control" type="number" min="5" max="525600" name="interval_minutes" value="<?=(int)$settings['interval_minutes']?>"></div><div class="col-md-4"><label class="form-label">Número de cópias</label><input class="form-control" type="number" min="1" max="365" name="retention" value="<?=(int)$settings['retention']?>"></div><div class="col-md-4 pt-4"><div class="form-check"><input class="form-check-input" type="checkbox" name="enabled" value="1" id="enabled" <?=$settings['enabled']?'checked':''?>><label class="form-check-label" for="enabled">Backup automático</label></div><div class="form-check"><input class="form-check-input" type="checkbox" name="include_files" value="1" id="files" <?=$settings['include_files']?'checked':''?>><label class="form-check-label" for="files">Incluir ficheiros</label></div></div><div class="col-12"><button class="btn btn-primary">Guardar agendamento</button></div></form><?php if($settings['last_error']):?><p class="text-danger small mt-3 mb-0"><?=h($settings['last_error'])?></p><?php endif;?></div></div></div>
 <div class="col-lg-5"><div class="card h-100"><div class="card-body"><h2 class="h5">Ações imediatas</h2><form method="post" class="mb-3"><?=csrf_input()?><input type="hidden" name="action" value="create"><label class="form-check mb-3"><input class="form-check-input" type="checkbox" name="include_files" value="1" checked> Incluir código, uploads e configuração</label><button class="btn btn-success"><i class="bi bi-database-add me-1"></i>Criar backup agora</button></form><hr><p class="small">Crie um token para aceder à consola mesmo se a aplicação sofrer um erro fatal. Guarde-o num gestor de palavras-passe.</p><form method="post"><?=csrf_input()?><button class="btn btn-outline-danger" name="action" value="generate_token">Gerar novo token de emergência</button></form></div></div></div>
</div>
<div class="card mb-4"><div class="card-header"><strong>Cópias disponíveis</strong></div><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Data UTC</th><th>Tipo</th><th>Tamanho</th><th>Tabelas</th><th></th></tr></thead><tbody><?php foreach($backups as $backup):$m=$backup['manifest'];?><tr><td><?=h((string)($m['created_at']??date('c',$backup['modified_at'])))?></td><td><?=!empty($m['includes_files'])?'Solução completa':'Base de dados'?></td><td><?=h(number_format($backup['size']/1048576,2,',','.'))?> MB</td><td><?=count($m['tables']??[]) ?></td><td><a class="btn btn-sm btn-outline-primary" href="?backup=<?=urlencode($backup['file'])?>">Selecionar para reposição parcial</a></td></tr><?php endforeach;?><?php if(!$backups):?><tr><td colspan="5" class="text-center text-muted py-4">Ainda não existem backups.</td></tr><?php endif;?></tbody></table></div></div>
<?php if($selectedManifest):?><div class="card border-warning"><div class="card-body"><h2 class="h5">Reposição parcial da base de dados</h2><p class="text-muted">Selecione apenas as tabelas necessárias. Antes da alteração será criado automaticamente um backup de segurança. Relações inválidas cancelam toda a operação.</p><form method="post" onsubmit="return confirm('Repor as tabelas selecionadas? Os dados atuais dessas tabelas serão substituídos.')"><?=csrf_input()?><input type="hidden" name="action" value="restore_tables"><input type="hidden" name="backup" value="<?=h($selectedFile)?>"><div class="row g-2 mb-3"><?php foreach((array)$selectedManifest['tables'] as $table):?><div class="col-md-4 col-xl-3"><label class="form-check border rounded p-2 ps-4"><input class="form-check-input" type="checkbox" name="tables[]" value="<?=h($table)?>"> <code><?=h($table)?></code></label></div><?php endforeach;?></div><button class="btn btn-warning">Repor tabelas selecionadas</button></form></div></div><?php endif;?>
<?php require __DIR__ . '/partials/footer.php'; ?>
