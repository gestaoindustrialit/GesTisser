<?php
require_once __DIR__.'/includes/assist_bootstrap.php';
header('Cache-Control: no-store');
try { $assistArticle=$assist->article((int)($_GET['id']??0));if(isset($_GET['version']) && (int)$_GET['version']!==(int)$assistArticle['version'])throw new RuntimeException('Versão indisponível.'); }
catch(Throwable $e){http_response_code(404);exit('Conteúdo indisponível.');}
$pageTitle='GesTISSER Assist — '.$assistArticle['title'];
$assistEmbedded=isset($_GET['embedded']);
if (!$assistEmbedded) require __DIR__.'/partials/header.php';
?>
<article class="card"><div class="card-body">
<h1 class="h4"><?=h($assistArticle['title'])?></h1>
<p class="text-muted">#<?=(int)$assistArticle['id']?> · v<?=(int)$assistArticle['version']?> · <?=h($assistArticle['category'])?> · <?=h($assistArticle['language'])?></p>
<p>Aprovado em <?=h($assistArticle['approved_at'])?> · Revisto em <?=h($assistArticle['reviewed_at'])?></p>
<?php foreach(['description'=>'Descrição','symptoms'=>'Sintomas','causes'=>'Causas documentadas','conditions'=>'Condições de aplicação','material'=>'Material','equipment'=>'Equipamento','operation'=>'Operação','safety'=>'Segurança','tools'=>'Materiais e ferramentas','procedure'=>'Procedimento','expected'=>'Resultado esperado','verification'=>'Verificação'] as $field=>$label): if(trim($assistArticle['content'][$field])==='')continue;?>
<section class="mb-3"><h2 class="h6"><?=h($label)?></h2><div style="white-space:pre-wrap" class="<?= $field==='safety'?'alert alert-warning':''?>"><?=h($assistArticle['content'][$field])?></div></section>
<?php endforeach;?>
</div></article>
<?php if(!$assistEmbedded)require __DIR__.'/partials/footer.php';?>
