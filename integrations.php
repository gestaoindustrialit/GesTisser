<?php
require_once __DIR__.'/helpers.php';
require_once __DIR__.'/integrations_migrations.php';
require_once __DIR__.'/includes/integrations/IntegrationClient.php';
require_once __DIR__.'/includes/integrations/IntegrationManager.php';
require_admin();
integrations_migrate($pdo);

// Some hosting configurations route unknown PHP files to the public website.
// Keep the integration editor reachable through this established entry point.
if (($_GET['action'] ?? '') === 'edit') {
    $integrationManager = new IntegrationManager($pdo);
    require __DIR__.'/integration_edit.php';
    return;
}

// The specialised connector uses the existing settings store and its own
// client-credentials diagnostics; generic API tests must not handle its secrets.
if (($_GET['action'] ?? '') === 'sharepoint') {
    require __DIR__.'/sharepoint_settings.php';
    return;
}
require_once __DIR__.'/app/Services/SharePointIntegration.php';
require_once __DIR__.'/app/Services/SharePointSchedule.php';
$spSchedule=(new SharePointSchedule($pdo))->settings();
$sharepoint = new SharePointIntegration($pdo, app_config('paths.uploads'));
$sharepointOverview = $sharepoint->overview();

$notice=$error='';
if($_SERVER['REQUEST_METHOD']==='POST'&&validate_csrf_or_abort(false)){try{$id=(int)($_POST['id']??0);if(($_POST['action']??'')==='toggle'){if(!app_config('external_services_enabled',false)){throw new RuntimeException('Integrações externas desativadas neste ambiente.');}$pdo->prepare('UPDATE integrations SET is_active=1-is_active,status=CASE WHEN is_active=1 THEN "off" ELSE "on" END WHERE id=?')->execute([$id]);$notice='Estado atualizado.';}elseif(($_POST['action']??'')==='test'){$s=$pdo->prepare('SELECT * FROM integrations WHERE id=?');$s->execute([$id]);$i=$s->fetch();$r=(new IntegrationClient($pdo))->request($i);$notice=$r['ok']?'Ligação estabelecida (HTTP '.$r['status'].').':'Erro HTTP '.$r['status'].': '.$r['error'];$pdo->prepare('UPDATE integrations SET status=?,last_result=? WHERE id=?')->execute([$r['ok']?'on':'error',$notice,$id]);}}catch(Throwable $e){$error=$e->getMessage();}}
$rows=$pdo->query('SELECT i.*,(SELECT COUNT(*) FROM integration_flows f WHERE f.integration_id=i.id AND f.is_active=1) active_flows,(SELECT MAX(started_at) FROM integration_runs r WHERE r.integration_id=i.id) last_run,(SELECT MIN(next_run_at) FROM integration_flows f WHERE f.integration_id=i.id AND f.is_active=1) next_run FROM integrations i ORDER BY i.name')->fetchAll();$pageTitle='Integrações';require __DIR__.'/partials/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4"><div><p class="text-muted mb-0">Administração · Ligações a aplicações externas</p></div><a class="btn btn-primary" href="integrations.php?action=edit"><i class="bi bi-plus-lg"></i> Nova integração</a></div>
<?php if($notice):?><div class="alert alert-success"><?=h($notice)?></div><?php endif;if($error):?><div class="alert alert-danger"><?=h($error)?></div><?php endif;?>
<div class="card shadow-sm"><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Nome</th><th>Provider / tipo</th><th>Estado</th><th>Ambiente</th><th>Última / próxima</th><th>Fluxos</th><th>Resultado</th><th>Ações</th></tr></thead><tbody>
<tr>
    <td><strong>Microsoft SharePoint — Artigos e Documentos</strong><br><code>sharepoint</code></td>
    <td>Microsoft SharePoint<br><small>OAuth 2.0 · client credentials</small></td>
    <td><span class="badge text-bg-<?=h($sharepointOverview['style'])?>"><?=h($sharepointOverview['state'])?></span></td>
    <td><?=h((string)app_config('env'))?></td>
    <td><small><?=h($sharepointOverview['checked_at'] ?: '—')?><br><?php if (!empty($spSchedule['enabled'])): ?>Agendamento ativo · Artigos: <?=h((string)$spSchedule['articles_minutes'])?> min · Documentos: <?=h((string)$spSchedule['documents_minutes'])?> min<?php else: ?>Agendamento desativado<?php endif; ?></small></td>
    <td><small>Artigos e documentos<br>Envios e agendamento no painel</small></td>
    <td><?=h($sharepointOverview['message'])?></td>
    <td class="text-nowrap">
        <a class="btn btn-sm btn-outline-primary" href="integrations.php?action=sharepoint">Configurar</a>
        <form class="d-inline" method="post" action="integrations.php?action=sharepoint#connections"><?=csrf_input()?><button name="action" value="all" class="btn btn-sm btn-outline-secondary">Testar</button></form>
        <a class="btn btn-sm btn-outline-secondary" href="integrations.php?action=sharepoint#diagnostics">Diagnóstico</a>
    </td>
</tr>
<?php foreach($rows as $r):$state=$r['status']==='error'?'danger':($r['is_active']?'success':'secondary');?><tr><td><strong><?=h($r['name'])?></strong><br><code><?=h($r['slug'])?></code></td><td><?=h($r['provider'])?><br><small><?=h($r['auth_type'])?></small></td><td><span class="badge text-bg-<?=$state?>">● <?=h($r['status'])?></span></td><td><?=h($r['environment'])?></td><td><small><?=h($r['last_run']?:'—')?><br><?=h($r['next_run']?:'—')?></small></td><td><?=(int)$r['active_flows']?></td><td><?=h($r['last_result']?:'—')?></td><td class="text-nowrap"><a class="btn btn-sm btn-outline-primary" href="integrations.php?action=edit&amp;id=<?=$r['id']?>">Editar</a> <form class="d-inline" method="post"><?=csrf_input()?><input type="hidden" name="id" value="<?=$r['id']?>"><button name="action" value="test" class="btn btn-sm btn-outline-secondary">Testar</button><button name="action" value="toggle" class="btn btn-sm btn-outline-danger"><?=$r['is_active']?'Desativar':'Ativar'?></button></form> <a class="btn btn-sm btn-outline-secondary" href="integration_logs.php?id=<?=$r['id']?>">Logs</a></td></tr><?php endforeach;?></tbody></table></div></div>
<?php require __DIR__.'/partials/footer.php';?>
