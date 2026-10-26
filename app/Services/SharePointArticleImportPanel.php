<?php
/** Included after require_admin(); POST handling is called only after CSRF validation. */
require_once __DIR__.'/SharePointArticleImport.php';
require_once __DIR__.'/SharePointArticleJob.php';
require_once __DIR__.'/SharePointDocumentSync.php';
function gt_sp_article_action(PDO $pdo, SharePointIntegration $integration, $action)
{
    if (!app_config('external_services_enabled',false)) throw new RuntimeException('Serviços externos desativados neste ambiente.');
    $documents=strpos($action,'documents_job_')===0;
    if($documents)$action=str_replace('documents_job_','articles_job_',$action);
    $settings=$integration->settings();
    $fingerprint=hash('sha256',json_encode($settings));
    if (strpos($action,'articles_job_')===0) {
        $lock=@fopen(sys_get_temp_dir().'/gestisser-sp-'.hash('sha256',$settings['site_id'].'/'.$settings['list_id']).'.lock','c');
        if (!$lock) throw new RuntimeException('Não foi possível bloquear a execução.');
        try {
            if (!flock($lock,LOCK_EX|LOCK_NB)) throw new RuntimeException('Outra operação está em curso. Aguarde e retome.');
            $jobs=new SharePointArticleJob($pdo,$documents?'documents':'articles');$run=(string)($_POST['run'] ?? '');
            if ($action==='articles_job_start') {
                $check=(new SharePointGraphClient($settings))->diagnose(SharePointClientSecret::read());
                if (isset($check['error'])) throw new RuntimeException('Resolva os erros de TESTAR SHAREPOINT antes de iniciar.');
                $job=$jobs->start($fingerprint);$_SESSION[$documents?'sp_document_autorun':'sp_article_autorun']=true;
            } elseif ($action==='articles_job_resume') {
                $job=$jobs->resume($run,$fingerprint);$_SESSION[$documents?'sp_document_autorun':'sp_article_autorun']=true;
            } elseif ($action==='articles_job_cancel') $job=$jobs->cancel($run);
            elseif ($action==='articles_job_pause') $job=$jobs->pause($run,$fingerprint);
            elseif ($action==='articles_job_step') {
                $job=$jobs->step($run,$fingerprint,function ($cursor,$maxId) use ($pdo,$settings,$documents) {
                    if($documents) return (new SharePointGraphClient($settings))->withDocuments(SharePointClientSecret::read(),function($api)use($pdo,$settings,$cursor,$maxId){
                        return (new SharePointDocumentSync($pdo,$api,app_config('paths.root'),$settings['drive_id']))->batch($cursor,$maxId);
                    });
                    return (new SharePointGraphClient($settings))->withArticles(SharePointClientSecret::read(),function ($api) use ($pdo,$cursor,$maxId) {
                        return (new SharePointArticleImport($pdo,$api))->syncBatch($cursor,$maxId);
                    },false);
                });
            } else throw new RuntimeException('Ação inválida.');
            $_SESSION[$documents?'sp_document_job':'sp_article_job']=$job;return $job;
        } finally { flock($lock,LOCK_UN);fclose($lock); }
    }
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
function gt_sp_article_panel(PDO $pdo)
{
    $job=(new SharePointArticleJob($pdo))->load();
    $autorun=!empty($_SESSION['sp_article_autorun']);unset($_SESSION['sp_article_autorun']);
    $preview=$_SESSION['sp_article_preview'] ?? null;
    $results=$_SESSION['sp_article_results'] ?? [];
    ?>
<h2 class="h5">3. Artigos — teste e envio completo</h2>
<p>Teste os primeiros cinco artigos antes de enviar todos. O envio completo cria os artigos em falta e atualiza os campos mapeados dos existentes por GesTISSER_ID, incluindo os códigos alterados. Registos iguais são ignorados. Não apaga registos, não copia documentos e não agenda tarefas. LinkGesTISSER fica por preencher.</p>
<p>Mapeamento: código → Title; descrição → Descrição; código e nome do cliente → CodigoCliente/Cliente; Ativo/Inativo → Estado; data → DataAtualizacao; última produção efetiva → DataUltimaProducao; documentos ativos → NumeroDocumentos; ID local → GesTISSER_ID.</p>
<form id="sp-job-form" method="post" action="integrations.php?action=sharepoint#article-import" class="mb-3">
<?=csrf_input()?><input type="hidden" name="run" value="<?=h($job['run'] ?? '')?>">
<?php if (in_array($job['state'] ?? '',['running','paused'],true)): ?>
<button class="btn btn-primary" name="action" value="articles_job_resume">Retomar envio completo</button>
<button class="btn btn-outline-secondary" name="action" value="articles_job_pause">Pausar</button>
<button class="btn btn-outline-secondary" name="action" value="articles_job_cancel">Encerrar execução</button>
<?php else: ?><button class="btn btn-primary" name="action" value="articles_job_start">Enviar/atualizar todos os artigos</button><?php endif; ?>
</form>
<p>O envio completo avança automaticamente enquanto esta página estiver aberta. Se fechar a página, volte e clique em Retomar. Pode voltar a executar depois para enviar novas alterações. O GesTISSER é a origem dos campos mapeados; outros campos SharePoint são preservados.</p>
<div id="sp-job-status" role="status" aria-live="polite"><?php if ($job): ?><?=h('Estado: '.$job['state'].' · Processados: '.$job['processed'].' / '.$job['total'].' · Criados: '.$job['created'].' · Atualizados: '.$job['updated'].' · Iguais: '.$job['unchanged'].' · Bloqueados: '.$job['blocked'])?><?php endif; ?></div>
<?php if (!empty($job['message'])): ?><p><?=h($job['message'])?></p><?php endif; ?>
<?php if (!empty($job['last_error'])): ?><div class="alert alert-warning"><strong>Diagnóstico da última interrupção</strong><dl>
<?php foreach ($job['last_error'] as $field=>$value): ?><dt><?=h($field)?></dt><dd><?=h(is_bool($value)?($value?'Sim':'Não'):(string)($value ?? '—'))?></dd><?php endforeach; ?></dl>
<p>HTTP 429/503/504 ou falha de rede: retoma com espera e reconciliação. HTTP 401/403: verificar autenticação e concessão ao site. HTTP 412: o registo mudou no SharePoint; retomar relê o ETag. Erro de coluna: confirmar que DataUltimaProducao existe com esse nome interno e tipo Data.</p></div><?php endif; ?>
<?php if (!empty($job['errors'])): ?><details><summary>Artigos bloqueados (primeiros 100)</summary><ul><?php foreach ($job['errors'] as $item): ?><li><?=h($item['code'].' ('.$item['id'].'): '.$item['error'])?></li><?php endforeach; ?></ul></details><?php endif; ?>
<?php if ($autorun && ($job['state'] ?? '')==='running'): ?>
<script>
(function () {
    var form=document.getElementById('sp-job-form'), status=document.getElementById('sp-job-status');
    async function step() {
        try {
            var data=new FormData(form);data.set('action','articles_job_step');
            var response=await fetch('integrations.php?action=sharepoint',{method:'POST',body:data,credentials:'same-origin'});
            var job=await response.json();
            if(!response.ok || job.error) throw new Error(job.error || 'Pedido interrompido');
            status.textContent='Estado: '+job.state+' · Processados: '+job.processed+' / '+job.total+' · Criados: '+job.created+' · Atualizados: '+job.updated+' · Iguais: '+job.unchanged+' · Bloqueados: '+job.blocked;
            if(job.state==='running')setTimeout(step,1000);
            else if(job.state==='paused' && job.retry_at) {
                var wait=Math.max(1,job.retry_at-Math.floor(Date.now()/1000));
                status.textContent+=' · Espera automática: '+wait+' segundos.';
                setTimeout(step,wait*1000);
            }
            else {status.textContent+=' · '+(job.message || 'Execução terminada.'); if(job.last_error)status.textContent+=' · Diagnóstico: '+JSON.stringify(job.last_error); status.textContent+=' · Atualize a página para consultar os detalhes.';}
        } catch(e) {status.textContent+=' · '+e.message+' · Atualize a página e clique em Retomar.';}
    }
    step();
})();
</script>
<?php endif; ?>
<h3 class="h6">Teste inicial — cinco artigos, apenas criação</h3>
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

function gt_sp_document_panel(PDO $pdo)
{
    $job=(new SharePointArticleJob($pdo,'documents'))->load();
    $autorun=!empty($_SESSION['sp_document_autorun']);unset($_SESSION['sp_document_autorun']);
    ?>
<h2 class="h5">4. Envio dos documentos para Artigos/Maquetes</h2>
<p>Envia documentos ativos PDF/JPG/PNG/WebP até 20 MiB para a biblioteca configurada, em Artigos/Maquetes/ID_DO_ARTIGO. Os nomes incluem o ID do documento e o SHA-256 para evitar colisões. Conteúdo alterado gera um novo ficheiro; versões anteriores são preservadas, sem apagar ou substituir ficheiros remotos.</p>
<p>Este envio é independente dos artigos e não cria anexos nativos na lista. NumeroDocumentos é a contagem no ERP; a contagem de enviados/confirmados aparece abaixo. Se já existir uma cópia sem integridade confirmada, o documento fica bloqueado para revisão.</p>
<form id="sp-document-job-form" method="post" action="integrations.php?action=sharepoint#document-import" class="mb-3">
<?=csrf_input()?><input type="hidden" name="run" value="<?=h($job['run'] ?? '')?>">
<?php if (in_array($job['state'] ?? '',['running','paused'],true)): ?>
<button class="btn btn-primary" name="action" value="documents_job_resume">Retomar documentos</button>
<button class="btn btn-outline-secondary" name="action" value="documents_job_pause">Pausar</button>
<button class="btn btn-outline-secondary" name="action" value="documents_job_cancel">Encerrar execução</button>
<?php else: ?><button class="btn btn-primary" name="action" value="documents_job_start">Enviar/verificar todos os documentos</button><?php endif; ?>
</form>
<p>O envio completo avança automaticamente enquanto esta página estiver aberta. Se fechar a página, volte e clique em Retomar. Pode voltar a executar depois para verificar os ficheiros e enviar os documentos novos ou alterados.</p>
<div id="sp-document-job-status" role="status" aria-live="polite"><?php if ($job): ?><?=h('Estado: '.$job['state'].' · Processados: '.$job['processed'].' / '.$job['total'].' · Enviados: '.$job['created'].' · Atualizados: '.$job['updated'].' · Confirmados: '.$job['unchanged'].' · Bloqueados: '.$job['blocked'])?><?php endif; ?></div>
<?php if (!empty($job['message'])): ?><p><?=h($job['message'])?></p><?php endif; ?>
<?php if (!empty($job['last_error'])): ?><div class="alert alert-warning"><strong>Diagnóstico da última interrupção</strong><dl>
<?php foreach ($job['last_error'] as $field=>$value): ?><dt><?=h($field)?></dt><dd><?=h(is_bool($value)?($value?'Sim':'Não'):(string)($value ?? '—'))?></dd><?php endforeach; ?></dl>
<p>HTTP 429/503/504 ou falha de rede: retoma com espera e reconciliação. HTTP 401/403: verificar autenticação e concessão ao site. HTTP 412: o registo mudou no SharePoint; retomar relê o ETag. Verifique o armazenamento local, o formato/tamanho do documento e a biblioteca configurada.</p></div><?php endif; ?>
<?php if (!empty($job['errors'])): ?><details><summary>Documentos bloqueados (primeiros 100)</summary><ul><?php foreach ($job['errors'] as $item): ?><li><?=h($item['code'].' ('.$item['id'].'): '.$item['error'])?></li><?php endforeach; ?></ul></details><?php endif; ?>
<?php if ($autorun && ($job['state'] ?? '')==='running'): ?>
<script>
(function () {
    var form=document.getElementById('sp-document-job-form'), status=document.getElementById('sp-document-job-status');
    async function step() {
        try {
            var data=new FormData(form);data.set('action','documents_job_step');
            var response=await fetch('integrations.php?action=sharepoint',{method:'POST',body:data,credentials:'same-origin'});
            var job=await response.json();
            if(!response.ok || job.error) throw new Error(job.error || 'Pedido interrompido');
            status.textContent='Estado: '+job.state+' · Processados: '+job.processed+' / '+job.total+' · Enviados: '+job.created+' · Atualizados: '+job.updated+' · Confirmados: '+job.unchanged+' · Bloqueados: '+job.blocked;
            if(job.state==='running')setTimeout(step,1000);
            else if(job.state==='paused' && job.retry_at) {
                var wait=Math.max(1,job.retry_at-Math.floor(Date.now()/1000));
                status.textContent+=' · Espera automática: '+wait+' segundos.';
                setTimeout(step,wait*1000);
            }
            else {status.textContent+=' · '+(job.message || 'Execução terminada.'); if(job.last_error)status.textContent+=' · Diagnóstico: '+JSON.stringify(job.last_error); status.textContent+=' · Atualize a página para consultar os detalhes.';}
        } catch(e) {status.textContent+=' · '+e.message+' · Atualize a página e clique em Retomar.';}
    }
    step();
})();
</script>
<?php endif; ?>
<?php
}
