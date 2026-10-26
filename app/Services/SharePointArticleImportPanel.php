<?php
/** Included after require_admin(); POST handling is called only after CSRF validation. */
require_once __DIR__.'/SharePointArticleImport.php';
function gt_sp_article_action(PDO $pdo, SharePointIntegration $integration, $action)
{
    if (!app_config('external_services_enabled',false)) throw new RuntimeException('Serviços externos desativados neste ambiente.');
    $settings=$integration->settings();
    $fingerprint=hash('sha256',json_encode($settings));
    if ($action==='articles_preview') {
        unset($_SESSION['sp_article_preview']);
        $after=filter_var($_POST['after_id'] ?? '0',FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
        if ($after===false) throw new RuntimeException('ID inicial inválido.');
        $preview=(new SharePointGraphClient($settings))->withArticles(SharePointClientSecret::read(),function ($api) use ($pdo,$after) {
            return (new SharePointArticleImport($pdo,$api))->preview($after);
        });
        $preview['configuration']=$fingerprint;
        $preview['nonce']=bin2hex(random_bytes(24));
        $_SESSION['sp_article_preview']=$preview;
        return;
    }
    $preview=$_SESSION['sp_article_preview'] ?? null;
    if (!is_array($preview) || !hash_equals($preview['nonce'],(string)($_POST['preview_nonce'] ?? ''))
        || $preview['configuration']!==$fingerprint) throw new RuntimeException('Pré-visualização inválida. Prepare novamente.');
    // Consume before network writes; a reload/double submission cannot replay this batch.
    unset($_SESSION['sp_article_preview']);
    $lock=@fopen(sys_get_temp_dir().'/gestisser-sp-'.hash('sha256',$settings['site_id'].'/'.$settings['list_id']).'.lock','c');
    if (!$lock) throw new RuntimeException('Não foi possível bloquear o lote.');
    try {
        if (!flock($lock,LOCK_EX|LOCK_NB)) throw new RuntimeException('Outra importação está em curso. Prepare novamente quando terminar.');
        $results=(new SharePointGraphClient($settings))->withArticles(SharePointClientSecret::read(),function ($api) use ($pdo,$preview) {
            return (new SharePointArticleImport($pdo,$api))->execute($preview);
        });
        $_SESSION['sp_article_results']=$results;
    } finally { flock($lock,LOCK_UN); fclose($lock); }
}
function gt_sp_article_panel()
{
    $preview=$_SESSION['sp_article_preview'] ?? null;
    $results=$_SESSION['sp_article_results'] ?? [];
    ?>
<h2 class="h5">3. Importação manual de artigos — lote até cinco</h2>
<p>Cria artigos em falta usando GesTISSER_ID. Não atualiza nem apaga artigos existentes. Documentos e sincronização automática não são executados. LinkGesTISSER fica por preencher neste piloto.</p>
<p>Mapeamento: código → Title; descrição → Descrição; código e nome do cliente → CodigoCliente/Cliente; Ativo/Inativo → Estado; data → DataAtualizacao; documentos ativos → NumeroDocumentos; ID local → GesTISSER_ID.</p>
<form method="post" action="integrations.php?action=sharepoint#article-import" class="mb-3">
<?=csrf_input()?><input type="hidden" name="action" value="articles_preview">
<label for="after-id">Preparar até cinco artigos com ID superior a</label>
<input id="after-id" name="after_id" type="number" min="0" value="<?=h((string)($preview['after'] ?? 0))?>">
<button class="btn btn-outline-primary">Preparar pré-visualização</button></form>
<?php if ($preview): ?>
<p>A pré-visualização expira em 15 minutos. Confirma os valores antes de importar.</p>
<div class="table-responsive"><table class="table table-sm"><thead><tr><th>ID</th><th>Código</th><th>Ação</th><th>Valores a enviar</th></tr></thead><tbody>
<?php $canImport=false; foreach ($preview['rows'] as $row): if ($row['action']==='Criar') $canImport=true; ?>
<tr><td><?=h((string)$row['id'])?></td><td><?=h($row['code'])?></td><td><?=h($row['action'])?><br><?=h($row['error'] ?? '')?></td><td><pre><?=h(json_encode($row['fields'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE))?></pre></td></tr>
<?php endforeach; ?></tbody></table></div>
<?php if (!$preview['rows']): ?><p>Não existem artigos neste intervalo.</p><?php endif; ?>
<?php if ($canImport): ?><form method="post" action="integrations.php?action=sharepoint#article-import">
<?=csrf_input()?><input type="hidden" name="action" value="articles_import"><input type="hidden" name="preview_nonce" value="<?=h($preview['nonce'])?>">
<button class="btn btn-primary">Importar os artigos assinalados para criar (máximo 5)</button></form><?php endif; ?>
<?php if ($preview['rows']): ?><p>Último ID deste lote: <?=h((string)max(array_column($preview['rows'],'id')))?>. Para preparar o lote seguinte, usa esse valor no campo acima.</p><?php endif; ?>
<?php endif; ?>
<?php if ($results): ?><h3 class="h6">Resultado do último lote nesta sessão</h3><ul>
<?php foreach ($results as $row): ?><li><?=h($row['code'])?> (ID <?=h((string)$row['id'])?>): <?=h($row['result'])?></li><?php endforeach; ?></ul><?php endif; ?>
<?php
}
