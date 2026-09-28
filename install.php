<?php
declare(strict_types=1);

require_once __DIR__ . '/app/Installer/CopyActivationService.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('gestisser_installer');
    session_start();
}

function installer_h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function installer_uuid()
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
}

$databasePath = getenv('GESTISSER_DB_PATH') ?: (__DIR__ . '/database.sqlite');
$service = new CopyActivationService(__DIR__, $databasePath, __DIR__ . '/storage');
$unlockRequested = getenv('GESTISSER_INSTALLER_UNLOCK') === '1';
if ($service->isLocked() && !$unlockRequested) {
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="pt"><meta charset="utf-8"><title>Instalador bloqueado</title><body><h1>Instalador bloqueado</h1><p>A instalação já foi concluída. Para uma reabertura administrativa explícita, defina temporariamente <code>GESTISSER_INSTALLER_UNLOCK=1</code>.</p><p><a href="login.php">Iniciar sessão</a></p></body></html>';
    exit;
}

if (empty($_SESSION['installer_csrf'])) {
    $_SESSION['installer_csrf'] = bin2hex(random_bytes(24));
}
$error = null;
$result = null;
$diagnosis = $service->diagnose();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        if (!hash_equals((string) $_SESSION['installer_csrf'], (string) ($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('O pedido expirou. Atualize a página e tente novamente.');
        }
        $action = (string) ($_POST['action'] ?? 'diagnose');
        if ($action === 'activate') {
            $result = $service->activate((string) ($_POST['environment'] ?? ''), trim((string) ($_POST['production_database_path'] ?? '')) ?: null);
            $diagnosis = $service->diagnose();
        } elseif ($action === 'new') {
            if (!in_array($diagnosis['state'], array('new','empty'), true)) {
                throw new RuntimeException('A instalação nova está bloqueada porque a localização contém dados. Indique GESTISSER_DB_PATH para uma base nova e vazia.');
            }
            $name = trim((string) ($_POST['name'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $environment = (string) ($_POST['environment'] ?? 'production');
            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 10 || !in_array($environment, array('production','test','development'), true)) {
                throw new RuntimeException('Preencha um nome, email válido, password com pelo menos 10 caracteres e ambiente.');
            }
            if (!is_dir(__DIR__ . '/storage')) { @mkdir(__DIR__ . '/storage', 0750, true); }
            $config = array(
                'installation_uuid'=>installer_uuid(), 'environment'=>$environment, 'database_path'=>$databasePath,
                'database_fingerprint'=>null, 'installed_at'=>gmdate('c'), 'activated_from_copy_at'=>null,
                'external_services_enabled'=>$environment === 'production', 'cron_enabled'=>false,
                'uploads_path'=>__DIR__.'/storage/uploads/'.$environment, 'logs_path'=>__DIR__.'/storage/logs/'.$environment,
                'session_name'=>'gestisser_'.$environment.'_'.substr(hash('sha256',$databasePath),0,12),
            );
            file_put_contents(__DIR__.'/storage/installation.json', json_encode($config, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), LOCK_EX);
            putenv('GESTISSER_DB_PATH=' . $databasePath);
            require_once __DIR__ . '/helpers.php';
            $admins = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE is_admin=1 AND COALESCE(is_active,1)=1')->fetchColumn();
            if ($admins > 0) { throw new RuntimeException('Já existe um administrador; nenhuma conta ou password foi alterada.'); }
            $insert = $pdo->prepare('INSERT INTO users(name,username,email,password,is_admin,access_profile,is_active,must_change_password) VALUES (?,?,?,?,1,?,1,0)');
            $insert->execute(array($name,$email,$email,password_hash($password,PASSWORD_DEFAULT),'Administração'));
            $config['database_fingerprint'] = hash_file('sha256',$databasePath);
            file_put_contents(__DIR__.'/storage/installation.json',json_encode($config,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),LOCK_EX);
            file_put_contents($service->lockPath(),json_encode(array('installation_uuid'=>$config['installation_uuid'],'locked_at'=>gmdate('c'))),LOCK_EX);
            $result = array('backup'=>null,'migrations'=>array('esquema inicial'),'config'=>$config);
            $diagnosis = $service->diagnose();
        }
    } catch (Throwable $exception) {
        error_log('[GesTisser installer] ' . $exception->getMessage());
        $error = $exception->getMessage();
    }
}

$stateLabels = array('new'=>'Instalação nova, sem base de dados','empty'=>'Base SQLite vazia','existing'=>'Cópia GesTISSER existente e compatível','invalid'=>'Base inválida ou incompatível');
?>
<!doctype html><html lang="pt"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Instalação segura — GesTISSER</title>
<style>body{font-family:system-ui,sans-serif;background:#f4f6f8;color:#17202a;margin:0}.wrap{max-width:1000px;margin:2rem auto;padding:0 1rem}.card{background:white;border:1px solid #dfe4ea;border-radius:12px;padding:1.4rem;margin-bottom:1rem;box-shadow:0 3px 12px #0001}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:.8rem}.metric{background:#f7f9fb;padding:.8rem;border-radius:8px}.ok{color:#18733c}.bad{color:#a51d2d}.alert{padding:1rem;border-radius:8px;background:#fff3cd;margin:1rem 0}.danger{background:#f8d7da}.success{background:#d1e7dd}.test{background:#ffc107;text-align:center;font-weight:800;padding:.7rem}label{display:block;font-weight:650;margin:.7rem 0 .25rem}input,select{box-sizing:border-box;width:100%;padding:.7rem;border:1px solid #aab2bd;border-radius:6px}button{padding:.75rem 1rem;border:0;border-radius:6px;background:#175cd3;color:white;font-weight:700;cursor:pointer}.secondary{background:#59636e}.disabled{opacity:.45;pointer-events:none}code{word-break:break-all}ul{line-height:1.55}.actions{display:flex;gap:.7rem;flex-wrap:wrap;margin-top:1rem}</style></head><body>
<?php if (($result['config']['environment'] ?? '') === 'test'): ?><div class="test">AMBIENTE DE TESTE</div><?php endif; ?>
<div class="wrap"><h1>Instalação segura do GesTISSER</h1><p>Diagnóstico sem alterações à base. Nenhuma migração é executada antes de confirmação e backup validado.</p>
<?php if ($error): ?><div class="alert danger"><strong>Operação abortada.</strong> <?= installer_h($error) ?></div><?php endif; ?>
<?php if ($result): ?><div class="card success"><h2>Ativação concluída</h2><ul><li>Ambiente: <strong><?= installer_h($result['config']['environment']) ?></strong></li><li>Base: <code><?= installer_h($databasePath) ?></code></li><li>Backup: <code><?= installer_h($result['backup']['path'] ?? 'não aplicável à instalação nova') ?></code></li><li>Migrações: <?= installer_h($result['migrations'] ? implode(', ',$result['migrations']) : 'nenhuma em falta') ?></li><li>Integrações externas: <?= !empty($result['config']['external_services_enabled']) ? 'permitidas pelo ambiente (continuam dependentes da configuração)' : 'desativadas' ?></li></ul><p><a href="login.php">Iniciar sessão</a>. Restrinja ou remova o acesso web a <code>install.php</code>.</p></div><?php else: ?>
<div class="card"><h2>Passo 1 — Diagnóstico</h2><p><strong><?= installer_h($stateLabels[$diagnosis['state']] ?? $diagnosis['state']) ?></strong></p><div class="grid">
<div class="metric">Base encontrada<br><strong><?= $diagnosis['exists']?'Sim':'Não' ?></strong></div><div class="metric">Tamanho<br><strong><?= number_format($diagnosis['size']/1024,1,',','.') ?> KiB</strong></div><div class="metric">Última alteração<br><strong><?= installer_h($diagnosis['modified_at'] ?: '—') ?></strong></div><div class="metric">Versão do esquema<br><strong><?= (int)$diagnosis['schema_version'] ?> / <?= CopyActivationService::SCHEMA_VERSION ?></strong></div><div class="metric">Tabelas<br><strong><?= (int)$diagnosis['table_count'] ?></strong></div><div class="metric">Integridade / FKs<br><strong class="<?= $diagnosis['integrity']==='ok'&&!$diagnosis['foreign_keys']?'ok':'bad' ?>"><?= installer_h($diagnosis['integrity']) ?> / <?= count($diagnosis['foreign_keys']) ?> erros</strong></div><div class="metric">Administradores válidos<br><strong><?= (int)$diagnosis['administrators'] ?></strong></div><div class="metric">Ambiente detetado<br><strong><?= installer_h($diagnosis['environment']) ?></strong></div></div>
<?php if ($diagnosis['errors'] || $diagnosis['missing_tables'] || $diagnosis['missing_columns']): ?><div class="alert danger">A ativação não é permitida. <?= installer_h(implode(' ',array_merge($diagnosis['errors'], $diagnosis['missing_tables'] ? array('Tabelas essenciais em falta: '.implode(', ',$diagnosis['missing_tables']).'.') : array(), $diagnosis['missing_columns'] ? array('Colunas mínimas em falta: '.implode(', ',$diagnosis['missing_columns']).'.') : array()))) ?></div><?php endif; ?></div>
<div class="card"><h2>Passo 2 — Escolha</h2><form method="post"><input type="hidden" name="csrf" value="<?= installer_h($_SESSION['installer_csrf']) ?>">
<?php if ($diagnosis['state']==='existing' && $diagnosis['compatible']): ?><h3>Ativar uma cópia existente do GesTISSER</h3><p>Mantém utilizadores, passwords, permissões, clientes, fornecedores, artigos, OFs, movimentos e saldos de stock. Aplica apenas migrações em falta, sem dados de demonstração, recriação de tabelas, importações históricas, emails ou ativação automática de integrações.</p><label>Ambiente obrigatório</label><select name="environment" required><option value="test">Teste</option><option value="development">Desenvolvimento</option><option value="production">Produção</option></select><label>Caminho conhecido da base de produção (obrigatório para confirmar separação quando aplicável)</label><input name="production_database_path" placeholder="/caminho/seguro/database.sqlite"><div class="alert">Será criado e validado um backup SHA-256 antes da migração. Migrações em falta: <strong><?= installer_h($diagnosis['pending_migrations']?implode(', ',$diagnosis['pending_migrations']):'nenhuma') ?></strong>.</div><button name="action" value="activate">Continuar: backup e ativação</button>
<?php else: ?><h3>Instalação nova</h3><p class="<?= in_array($diagnosis['state'],array('new','empty'),true)?'':'bad' ?>">Só é permitida numa localização inexistente ou SQLite vazia. Uma base preenchida nunca é apagada ou recriada.</p><label>Ambiente</label><select name="environment"><option value="production">Produção</option><option value="test">Teste</option><option value="development">Desenvolvimento</option></select><label>Nome do primeiro administrador</label><input name="name"><label>Email</label><input type="email" name="email"><label>Password (mínimo 10 caracteres)</label><input type="password" name="password"><button class="<?= in_array($diagnosis['state'],array('new','empty'),true)?'':'disabled' ?>" name="action" value="new">Instalação nova</button><?php endif; ?>
<div class="actions"><button class="secondary" name="action" value="diagnose">Apenas diagnosticar</button><a href="login.php">Cancelar</a></div></form></div><?php endif; ?></div></body></html>
