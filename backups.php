<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/app/Services/BackupManager.php';
require_admin();

$manager = new BackupManager($pdo, __DIR__);
$manager->ensureSchema();
$success = $error = '';

if (isset($_GET['download'])) {
    $path = $manager->pathFor((string) $_GET['download']);
    if (!$path) { http_response_code(404); exit('Backup não encontrado.'); }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . basename($path) . '"');
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && validate_csrf_or_abort(false)) {
    try {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'settings') {
            $manager->saveSettings((string) ($_POST['schedule'] ?? ''), (string) ($_POST['time'] ?? ''), (int) ($_POST['retention'] ?? 14));
            $success = 'Programação guardada.';
            if ($manager->isDue()) {
                $manager->create('scheduled', (int) $_SESSION['user_id']);
                $success = 'Programação guardada e primeiro backup automático criado.';
            }
        } elseif ($action === 'create') {
            $result = $manager->create('manual', (int) $_SESSION['user_id']);
            log_app_event($pdo, (int) $_SESSION['user_id'], 'backup.created', 'Backup manual criado.', ['filename' => $result['filename']]);
            $success = 'Backup completo criado e verificado.';
        }
    } catch (Throwable $exception) { $error = $exception->getMessage(); }
}
$settings = $manager->settings();
$runs = $manager->listRuns();
$pageTitle = 'Backups e recuperação';
require __DIR__ . '/partials/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
  <div><h1 class="h3 mb-1">Backups e recuperação</h1><p class="text-muted mb-0">Proteja a base de dados e os ficheiros enviados, descarregue cópias e consulte o estado de cada execução.</p></div>
  <form method="post"><?= csrf_input() ?><input type="hidden" name="action" value="create"><button class="btn btn-primary"><i class="bi bi-cloud-arrow-up me-1"></i>Criar backup agora</button></form>
</div>
<?php if ($success): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><strong>Não foi possível criar o backup:</strong> <?= h($error) ?></div><?php endif; ?>
<div class="row g-4 mb-4">
 <div class="col-lg-5"><div class="card shadow-sm h-100"><div class="card-body"><h2 class="h5">Programação automática</h2><p class="text-muted small">Ao guardar uma hora já vencida, a primeira cópia é criada imediatamente. Depois, o primeiro acesso ao sistema após a hora programada inicia a cópia. O cron a cada 5 minutos continua recomendado para garantir backups mesmo sem visitas.</p>
  <form method="post" class="row g-3"><?= csrf_input() ?><input type="hidden" name="action" value="settings">
   <div class="col-12"><label class="form-label" for="schedule">Periodicidade</label><select class="form-select" id="schedule" name="schedule"><?php foreach (['disabled'=>'Desativada','daily'=>'Diária','weekly'=>'Semanal (segunda-feira)','monthly'=>'Mensal (dia 1)'] as $value=>$label): ?><option value="<?= h($value) ?>" <?= $settings['schedule']===$value?'selected':'' ?>><?= h($label) ?></option><?php endforeach; ?></select></div>
   <div class="col-sm-6"><label class="form-label" for="time">Hora</label><input class="form-control" type="time" id="time" name="time" value="<?= h($settings['time']) ?>" required></div>
   <div class="col-sm-6"><label class="form-label" for="retention">Cópias a conservar</label><input class="form-control" type="number" id="retention" name="retention" min="1" max="365" value="<?= (int)$settings['retention'] ?>" required></div>
   <div class="col-12"><button class="btn btn-outline-primary">Guardar programação</button></div>
  </form></div></div></div>
 <div class="col-lg-7"><div class="card border-warning-subtle bg-warning-subtle h-100"><div class="card-body"><h2 class="h5"><i class="bi bi-life-preserver me-2"></i>Recuperação após falha fatal</h2>
  <ol class="mb-2"><li>Descarregue regularmente a cópia mais recente para outro servidor ou disco.</li><li>Numa falha, coloque a aplicação em manutenção e guarde o ficheiro avariado.</li><li>Extraia o ZIP e substitua <code>database.sqlite</code>; copie também as pastas <code>uploads/</code>, <code>assets/uploads/</code> e <code>storage/uploads/</code>.</li><li>Reponha permissões, inicie a aplicação e confirme <code>PRAGMA integrity_check</code>.</li></ol>
  <p class="mb-0 small">O procedimento completo, incluindo comandos para um servidor indisponível, está em <a href="docs/RECUPERACAO_BACKUPS.md" target="_blank">RECUPERACAO_BACKUPS.md</a>. Nunca restaure por cima de uma aplicação em execução.</p>
 </div></div></div>
</div>
<div class="card shadow-sm"><div class="card-header bg-white"><h2 class="h5 mb-0">Histórico</h2></div><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>Data</th><th>Origem</th><th>Estado</th><th>Tamanho</th><th>SHA-256</th><th></th></tr></thead><tbody>
<?php if (!$runs): ?><tr><td colspan="6" class="text-center text-muted py-4">Ainda não existem backups.</td></tr><?php endif; ?>
<?php foreach ($runs as $run): ?><tr><td><?= h($run['finished_at'] ?: $run['created_at']) ?></td><td><?= $run['trigger_type']==='scheduled'?'Automático':'Manual' ?></td><td><?php if ($run['status']==='success'): ?><span class="badge text-bg-success">Concluído</span><?php elseif ($run['status']==='failed'): ?><span class="badge text-bg-danger" title="<?= h($run['message']) ?>">Falhou</span><?php else: ?><span class="badge text-bg-warning">Em curso</span><?php endif; ?></td><td><?= $run['size_bytes'] ? h(number_format(((int)$run['size_bytes'])/1048576, 2, ',', '.').' MB') : '—' ?></td><td><code class="small"><?= h($run['checksum'] ? substr($run['checksum'],0,12).'…' : '—') ?></code></td><td class="text-end"><?php if ($run['status']==='success' && $manager->pathFor((string)$run['filename'])): ?><a class="btn btn-sm btn-outline-primary" href="?download=<?= rawurlencode($run['filename']) ?>"><i class="bi bi-download me-1"></i>Download</a><?php else: ?>—<?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<?php require __DIR__ . '/partials/footer.php'; ?>
