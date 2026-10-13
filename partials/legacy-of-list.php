<?php
/** Reusable consultation inside existing customer/article history tabs. */
require_once __DIR__.'/../historical-import/LegacyOfHistory.php';
$legacyReader=new LegacyOfHistory($pdo);
$legacyInput=$_GET;
if(isset($legacyCustomerId))$legacyInput['customer']=(string)$legacyCustomerId;
if(isset($legacyArticleId))$legacyInput['article']=(string)$legacyArticleId;
$legacyFilters=LegacyOfHistory::filters($legacyInput);
$legacyUnified=isset($legacyStandalone)&&$legacyStandalone;
if(!$legacyUnified&&!isset($_GET['origin']))$legacyFilters['origin']='Bobinas';
$legacyList=$legacyReader->listing($legacyFilters,true);
$legacyLink=function(array $changes)use($legacyInput){return '?'.http_build_query(array_merge($legacyInput,$changes));};
?>
<section class="border rounded p-3 my-4" aria-label="Consulta de OFs históricas">
<h2 class="h5"><?= $legacyUnified?'Consulta de OFs · GesTISSER e Bobinas':'Histórico Bobinas' ?></h2>
<?php if(!$legacyReader->ready()):?><p class="text-secondary">Estrutura histórica ainda não preparada.</p><?php endif;?>
<form method="get" class="row g-2 mb-3">
<?php foreach(isset($legacyArticleId)?['page','id','tab','customer','article']:['page','id','tab','customer'] as $key):if(isset($legacyInput[$key])&&is_scalar($legacyInput[$key])):?><input type="hidden" name="<?= h($key) ?>" value="<?= h($legacyInput[$key]) ?>"><?php endif;endforeach;?>
<div class="col-sm-2"><label class="form-label">Ano<input class="form-control" name="year" value="<?= h($legacyFilters['year']) ?>" inputmode="numeric" maxlength="4"></label></div>
<div class="col-sm-3"><label class="form-label">Número da OF<input class="form-control" name="q" value="<?= h($legacyFilters['q']) ?>"></label></div>
<?php if(!isset($legacyArticleId)):?><div class="col-sm-2"><label class="form-label">ID do artigo<input class="form-control" name="article" value="<?= $legacyFilters['article']?:'' ?>" inputmode="numeric"></label></div><?php endif;?>
<div class="col-sm-2"><label class="form-label">Origem<select class="form-select" name="origin"><?php foreach([''=>'Todas','GesTISSER'=>'GesTISSER','Bobinas'=>'Bobinas'] as $key=>$label):?><option value="<?= h($key) ?>" <?= $legacyFilters['origin']===$key?'selected':'' ?>><?= h($label) ?></option><?php endforeach;?></select></label></div>
<div class="col-sm-3 align-self-end mb-2"><button class="btn btn-outline-primary">Filtrar</button> <a href="legacy_of_history.php?<?= h(http_build_query(['customer'=>$legacyFilters['customer'],'article'=>$legacyFilters['article']])) ?>">Consulta unificada</a></div>
<?php if(!empty($legacyFilters['pending'])):?><input type="hidden" name="pending" value="1"><?php endif;?>
</form>
<p class="small text-secondary"><?= (int)$legacyList['total'] ?> OFs · página <?= (int)$legacyList['page'] ?> de <?= (int)$legacyList['pages'] ?>. Quantidades sem unidade registada mantêm essa indicação.</p>
<div class="table-responsive"><table class="table table-sm"><thead><tr><th>OF</th><th>Data</th><th>Cliente</th><th>Artigo</th><th>Produzido</th><th>Origem / Estado</th></tr></thead><tbody>
<?php foreach($legacyList['rows'] as $r):?><tr><td><a href="<?= $r['origin']==='Bobinas'?'legacy_of_history.php':'production_dossier.php' ?>?id=<?= (int)$r['id'] ?>"><?= h($r['number']) ?></a></td><td><?= h($r['date']?:'Sem dados') ?></td><td><?= h(($r['customer_code']?:'Sem código').' · '.($r['customer_name']?:'Pendente')) ?></td><td><?php if($r['article_id']):?><a href="erp.php?page=article_profile&amp;id=<?= (int)$r['article_id'] ?>"><?= h($r['article_code']) ?></a><?php else:?><?= h($r['article_code']?:'Sem código') ?><?php endif;?> · <?= h($r['article_name']?:'Pendente') ?></td><td><?= h($r['produced_quantity']===null?'Sem dados':$r['produced_quantity'].' '.($r['unit']?:'(unidade não registada)')) ?></td><td><span class="badge bg-secondary"><?= h($r['origin']) ?><?= $r['origin']==='Bobinas'?' · histórico':'' ?></span> <?= h($r['status']?:'Sem estado registado') ?></td></tr><?php endforeach;?>
<?php if(!$legacyList['rows']):?><tr><td colspan="6">Sem OFs para estes filtros.</td></tr><?php endif;?>
</tbody></table></div>
<?php if($legacyList['page']>1):?><a class="btn btn-sm btn-outline-secondary" href="<?= h($legacyLink(['p'=>$legacyList['page']-1])) ?>">Anterior</a><?php endif;?>
<?php if($legacyList['page']<$legacyList['pages']):?><a class="btn btn-sm btn-outline-secondary" href="<?= h($legacyLink(['p'=>$legacyList['page']+1])) ?>">Seguinte</a><?php endif;?>
</section>
