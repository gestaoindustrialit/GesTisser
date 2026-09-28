<?php
declare(strict_types=1);
// Deliberadamente sem bootstrap, base de dados, autenticação ou templates da aplicação.
require_once __DIR__ . '/app/Services/BackupManager.php';
session_name('gestisser_recovery');
session_start(['cookie_httponly' => true]);
$manager = new BackupManager(__DIR__);
$tokenHash = is_file(__DIR__ . '/storage/recovery.token') ? trim((string) file_get_contents(__DIR__ . '/storage/recovery.token')) : '';
$environmentToken = (string) getenv('GESTISSER_RECOVERY_TOKEN');
$authError = '';
if (isset($_POST['token'])) {
    $candidate = (string) $_POST['token'];
    $valid = ($environmentToken !== '' && hash_equals($environmentToken, $candidate)) || ($tokenHash !== '' && password_verify($candidate, $tokenHash));
    if ($valid) { session_regenerate_id(true); $_SESSION['recovery_authenticated'] = time(); } else { $authError = 'Token inválido.'; sleep(1); }
}
$authenticated = isset($_SESSION['recovery_authenticated']);
$notice=''; $error='';
if ($authenticated && $_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action'])) {
    try {
        if (!hash_equals((string)($_SESSION['recovery_csrf']??''),(string)($_POST['_token']??''))) throw new RuntimeException('Pedido expirado.');
        if ($_POST['action']==='create') { $r=$manager->create(true,'emergency-console'); $notice='Backup criado: '.$r['file']; }
        if ($_POST['action']==='restore_database') { $r=$manager->restoreDatabase((string)$_POST['backup']); $notice='Base de dados reposta. Backup anterior: '.$r['safety_backup']; }
        if ($_POST['action']==='restore_tables') { $r=$manager->restoreTables((string)$_POST['backup'],(array)($_POST['tables']??[])); $notice='Tabelas repostas: '.implode(', ',$r['tables']); }
        if ($_POST['action']==='restore_files') { $r=$manager->restoreSolutionFiles((string)$_POST['backup']); $notice=$r['files'].' ficheiros repostos. Backup anterior: '.$r['safety_backup']; }
    } catch(Throwable $e){$error=$e->getMessage();}
}
if (!isset($_SESSION['recovery_csrf'])) {
    $_SESSION['recovery_csrf'] = bin2hex(random_bytes(24));
}
$selected=(string)($_GET['backup']??''); $manifest=[];
if($authenticated&&$selected!==''){try{$manifest=$manager->manifest($selected);}catch(Throwable $e){$error=$e->getMessage();}}
?><!doctype html><html lang="pt-PT"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Recuperação independente · GesTisser</title><style>body{font:16px system-ui;background:#111827;color:#e5e7eb;margin:0}.wrap{max-width:1100px;margin:3rem auto;padding:1.5rem}.card{background:#1f2937;border:1px solid #374151;border-radius:12px;padding:1.5rem;margin-bottom:1rem}input,button{font:inherit;padding:.65rem;border-radius:6px;border:1px solid #6b7280}button{background:#2563eb;color:white;cursor:pointer}.danger{background:#b91c1c}.alert{padding:1rem;border-radius:8px;background:#064e3b}.error{background:#7f1d1d}table{width:100%;border-collapse:collapse}td,th{text-align:left;padding:.7rem;border-bottom:1px solid #374151}a{color:#93c5fd}.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:.5rem}.check{background:#111827;padding:.6rem;border-radius:6px}code{color:#bfdbfe}</style></head><body><main class="wrap"><h1>Consola de recuperação independente</h1><p>Esta página não inicia a aplicação nem abre a base de dados até ser necessário.</p><?php if($authError):?><div class="alert error"><?=htmlspecialchars($authError)?></div><?php endif;?><?php if(!$authenticated):?><div class="card"><form method="post"><label>Token de emergência<br><input type="password" name="token" autofocus required></label> <button>Entrar</button></form><p>Defina <code>GESTISSER_RECOVERY_TOKEN</code> no servidor ou gere um token na área de administração.</p></div><?php else:?><?php if($notice):?><div class="alert"><?=htmlspecialchars($notice)?></div><?php endif;?><?php if($error):?><div class="alert error"><?=htmlspecialchars($error)?></div><?php endif;?><div class="card"><h2>Backup imediato</h2><form method="post"><input type="hidden" name="_token" value="<?=htmlspecialchars($_SESSION['recovery_csrf'])?>"><button name="action" value="create">Criar backup geral agora</button></form></div><div class="card"><h2>Backups disponíveis</h2><table><tr><th>Ficheiro</th><th>Data</th><th></th></tr><?php foreach($manager->backups() as $b):?><tr><td><?=htmlspecialchars($b['file'])?></td><td><?=htmlspecialchars((string)($b['manifest']['created_at']??''))?></td><td><a href="?backup=<?=urlencode($b['file'])?>">Abrir</a></td></tr><?php endforeach;?></table></div><?php if($manifest):?><div class="card"><h2>Repor <?=htmlspecialchars($selected)?></h2><form method="post" onsubmit="return confirm('Substituir TODA a base de dados atual?')"><input type="hidden" name="_token" value="<?=htmlspecialchars($_SESSION['recovery_csrf'])?>"><input type="hidden" name="backup" value="<?=htmlspecialchars($selected)?>"><button class="danger" name="action" value="restore_database">Repor base de dados completa</button> <?php if(!empty($manifest['includes_files'])):?><button class="danger" name="action" value="restore_files" onclick="return confirm('Substituir os ficheiros da solução?')">Repor ficheiros da solução</button><?php endif;?></form><hr><h3>Ou repor apenas tabelas</h3><form method="post" onsubmit="return confirm('Substituir os dados das tabelas selecionadas?')"><input type="hidden" name="_token" value="<?=htmlspecialchars($_SESSION['recovery_csrf'])?>"><input type="hidden" name="backup" value="<?=htmlspecialchars($selected)?>"><div class="grid"><?php foreach((array)$manifest['tables'] as $t):?><label class="check"><input type="checkbox" name="tables[]" value="<?=htmlspecialchars($t)?>"> <?=htmlspecialchars($t)?></label><?php endforeach;?></div><p><button name="action" value="restore_tables">Repor tabelas</button></p></form></div><?php endif;?><?php endif;?></main></body></html>
