<?php
require_once __DIR__.'/helpers.php';
require_admin();
// Preserve old bookmarks; configuration and all forms now live under Integrations.
if (basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'sharepoint_settings.php'
    && $_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Location: integrations.php?action=sharepoint');
    exit;
}
require_once __DIR__.'/app/Services/SharePointIntegration.php';
$integration=new SharePointIntegration($pdo,app_config('paths.uploads'));
$message=$error='';
if ($_SERVER['REQUEST_METHOD']==='POST' && validate_csrf_or_abort(false)) {
    try {
        $action=(string)($_POST['action'] ?? '');
        if ($action==='save') { $integration->save($_POST); $message='Configuração guardada. Os anexos existentes mantêm o seu armazenamento atual.'; }
        elseif (in_array($action,['local','remote','all'],true)) {
            if ($action==='local' || $action==='all') $integration->diagnostic('cpanel',[$integration,'testStorage']);
            if ($action==='remote' || $action==='all') $integration->diagnostic('graph',function () use ($integration) {
                if (!app_config('external_services_enabled',false)) throw new RuntimeException('Serviços externos desativados neste ambiente. Ative-os apenas no ambiente de teste autorizado.');
                $client=new SharePointGraphClient($integration->settings());
                return $client->diagnose(SharePointClientSecret::read());
            });
            $message='Verificação concluída. Consulte o resultado de cada serviço abaixo.';
        }
    } catch (Throwable $e) { $error=$e->getMessage(); }
}
$settings=$integration->settings();
$pageTitle='SharePoint e Armazenamento';
require __DIR__.'/partials/header.php';
?>
<p class="text-muted">Administração → <a href="integrations.php">Integrações</a> → SharePoint e Armazenamento</p>
<h1 class="h3">Microsoft SharePoint — Artigos e Documentos</h1>
<p><a href="integrations.php">← Voltar às integrações</a></p>
<?php if ($message): ?><div class="alert alert-info"><?=h($message)?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?=h($error)?></div><?php endif; ?>
<div class="card mb-4"><div class="card-body"><h2 class="h5">1. Configuração dos servidores</h2>
<form method="post" action="integrations.php?action=sharepoint" class="row g-3"><?=csrf_input()?><input type="hidden" name="action" value="save">
<?php foreach (['storage_path'=>'Diretório local a diagnosticar (não move documentos)','max_bytes'=>'Limite pretendido (bytes; não altera o limite atual de upload)','tenant_id'=>'Tenant ID','client_id'=>'Client ID','site_url'=>'URL do SharePoint','site_id'=>'Site ID','list_id'=>'List ID dos artigos','drive_id'=>'Drive ID da biblioteca documental'] as $key=>$label): ?>
<div class="col-md-6"><label for="<?=h($key)?>" class="form-label"><?=h($label)?></label><input class="form-control" id="<?=h($key)?>" name="<?=h($key)?>" value="<?=h((string)$settings[$key])?>" <?= $key==='max_bytes'?'type="number" min="1"':'type="text"' ?>></div>
<?php endforeach; ?>
<div class="col-md-6"><p>Client Secret disponível: <?=SharePointClientSecret::available() ? 'Sim' : 'Não'?></p><small>Configurado no servidor por variável de ambiente ou ficheiro privado.</small></div>
<div class="col-md-6"><label class="form-label">Sincronização automática</label><select class="form-select" disabled><option>Indisponível nesta versão</option></select></div>
<div class="col-12"><button class="btn btn-primary">Guardar configuração</button></div></form></div></div>
<div class="card mb-4"><div class="card-body"><h2 id="connections" class="h5">2. Estado das ligações</h2>
<form method="post" action="integrations.php?action=sharepoint#connections" class="d-flex flex-wrap gap-2 mb-3"><?=csrf_input()?><button name="action" value="local" class="btn btn-outline-primary">TESTAR CPANEL</button><button name="action" value="remote" class="btn btn-outline-primary">TESTAR SHAREPOINT</button><button name="action" value="all" class="btn btn-primary">VERIFICAR TODAS AS LIGAÇÕES</button></form>
<p class="small text-muted">Os resultados abaixo pertencem à última verificação concluída. Os testes Graph são apenas de leitura; a escrita ainda não foi validada. Recarregar a página consulta os resultados guardados.</p>
<div class="row g-3"><?php foreach (['cpanel'=>'Servidor cPanel (sistema de ficheiros local)','graph'=>'Microsoft SharePoint'] as $key=>$label): $result=$integration->lastDiagnostic($key); ?>
<div class="col-md-6"><h3 class="h6"><?=h($label)?></h3><p><?=h($result['state'] ?? 'Não testado')?><br><small>Último teste: <?=h($result['checked_at'] ?? '—')?></small></p>
<?php if (isset($result['error'])): ?><div class="alert alert-danger"><?=h($result['error'])?></div><?php endif; ?>
<?php if (!empty($result['token_checks'])): ?>
<p><?=h($result['token_origin'] ?? '')?></p>
<ul><?php foreach ($result['token_checks'] as $check=>$passed): ?><li><?=h($check)?>: <?= $passed ? 'Sim' : 'Não' ?></li><?php endforeach; ?></ul>
<p class="small text-muted"><?=h($result['claims_note'] ?? '')?></p>
<?php endif; ?>
<?php if (!empty($result['requests'])): ?>
<div class="table-responsive"><table class="table table-sm"><thead><tr><th>Endpoint lógico</th><th>HTTP</th><th>Código Graph / OAuth2</th><th>request-id</th><th>Diagnóstico</th></tr></thead><tbody>
<?php foreach ($result['requests'] as $request): ?><tr><td><code><?=h($request['endpoint'])?></code></td><td><?=h($request['http_status'] ?? '—')?></td><td><?=h($request['graph_error_code'] ?? ($request['oauth_error_code'] ?? '—'))?></td><td><?=h($request['request_id'] ?? '—')?></td><td><?=h($request['message'])?><?php if (!empty($request['graph_message'])): ?><br>Mensagem Graph: <?=h($request['graph_message'])?><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>
<?php if (isset($result['free_bytes'])): ?><p>Espaço disponível: <?=h(number_format($result['free_bytes']/1048576,1,',','.'))?> MiB</p><?php endif; ?>
<?php foreach (['site_id','list_id','drive_id'] as $field) if (isset($result[$field])): ?><p><?=h($field)?>: <code><?=h($result[$field])?></code></p><?php endif; ?>
</div><?php endforeach; ?></div></div></div>
<div class="card mb-4"><div class="card-body"><h2 class="h5">3. Sincronização dos artigos</h2><p>Ainda indisponível. É necessário validar as colunas reais, o identificador estável e o mapeamento antes de permitir escritas.</p></div></div>
<div class="card mb-4"><div class="card-body"><h2 class="h5">4. Sincronização dos documentos</h2><p>Ainda indisponível. O fluxo atual de anexos mantém-se operacional; ainda não há replicação remota.</p></div></div>
<div class="card mb-4"><div class="card-body"><h2 id="diagnostics" class="h5">5. Histórico e diagnóstico</h2><p>Esta primeira etapa conserva o último diagnóstico de cada serviço. Histórico de sincronizações e fila serão acrescentados com o worker.</p>
<p>Client Secret disponível: <?=SharePointClientSecret::available() ? 'Sim' : 'Não'?></p>
<?php $remote=$integration->lastDiagnostic('graph'); ?>
<?php if (!empty($remote['configuration'])): ?><h3 class="h6">Configuração usada no último teste</h3>
<?php foreach ($remote['configuration'] as $field=>$value): ?><p><?=h($field)?>: <code><?=h($value)?></code></p><?php endforeach; endif; ?>
<p><?=h($remote['site_identity'] ?? 'Identidade do site ainda não verificada.')?></p>
<?php if (isset($remote['site_display_name'])): ?><p>Nome devolvido pelo Graph: <?=h($remote['site_display_name'])?></p><?php endif; ?>
<p>Roles da aplicação no token: <?=h(implode(', ', $remote['application_roles'] ?? []))?></p>
<p><?=h($remote['site_permission_note'] ?? '')?></p>
<?php if (!empty($remote['first_403_endpoint'])): ?><p class="text-danger">Primeiro HTTP 403: <code><?=h($remote['first_403_endpoint'])?></code></p><?php endif; ?>
<p>Se persistir o 403: no Microsoft Entra, abrir Aplicações → Registos de aplicações → aplicação correspondente ao Client ID → Permissões de API. Consultar Microsoft Graph, Sites.Selected, tipo Application e consentimento administrativo. Não alterar permissões.</p>
<p>No Centro de administração SharePoint → Sites ativos → Tisser Central, confirmar o URL. Uma identidade administrativa já autorizada deve consultar GET /sites/{site_id}/permissions e comparar a aplicação em grantedToIdentities/grantedToIdentitiesV2 e as roles com os IDs acima. Esta consulta não é executada pela aplicação do ERP.</p>
<p>Recolher apenas o URL, Site ID, Client ID, roles da concessão e request-id. Nunca partilhar segredo ou token. Mensagens Graph abaixo são limitadas e redigidas; os corpos completos não são guardados.</p>
</div></div>
<?php require __DIR__.'/partials/footer.php'; ?>
