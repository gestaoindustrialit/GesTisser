<?php
// Read-only bootstrap: do not trigger the legacy ERP's schema/status writes.
function db() {return $GLOBALS['pdo'];}
function has_shopfloor_only_navigation(array $user): bool {
    return (int)($user['is_admin']??0)!==1 && ((int)($user['pin_only_login']??0)===1 || (string)($user['access_profile']??'')==='Utilizador');
}
require_once __DIR__.'/bootstrap/app.php';
require_once __DIR__.'/erp_migrations.php';
require_once __DIR__.'/app/Services/ArticleProfile.php';
require_once __DIR__.'/article_document.php';
require_once __DIR__.'/app/Services/ArticleTheoreticalWeight.php';
require_once __DIR__.'/app/Services/ArticlePalletWeight.php';
$path=app_config('db_path');
if(!is_file($path)){http_response_code(503);exit('Base de dados indisponível.');}
$pdo=new PDO('sqlite:'.$path);$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$pdo->exec('PRAGMA query_only=ON');
require_login();$user=current_user($pdo)?:[];
if(!gt_erp_user_can($pdo,$user,'erp.view')){http_response_code(403);exit('Sem permissão para consultar artigos.');}
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){http_response_code(405);header('Allow: GET');exit('A ficha é de consulta.');}
$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT)?:0;
$service=new ArticleProfile($pdo);$article=$id>0?$service->article($id):null;
if(!$article){http_response_code(404);exit('Artigo não encontrado.');}
$financial=gt_erp_user_can($pdo,$user,'erp.costs_view');$canRouting=gt_erp_user_can($pdo,$user,'erp.routings.view');
$tabs=['overview'=>'Visão Geral','technical'=>'Dados Técnicos','routing'=>'Routing','history'=>'Encomendas / OF','costs'=>'Histórico / Custeio','trace'=>'Rastreabilidade','documents'=>'Documentos'];
$tab=is_scalar($_GET['tab']??'')?(string)($_GET['tab']??'overview'):'overview';if(!isset($tabs[$tab]))$tab='overview';
if(($tab==='costs'&&!$financial)||($tab==='routing'&&!$canRouting)){http_response_code(403);exit('Sem permissão para consultar este separador.');}
$filters=ArticleProfile::filters($_GET);
function ap_url($id,$tab='overview',array $filters=[]) {return 'erp.php?'.http_build_query(array_merge(['page'=>'article_profile','id'=>$id,'tab'=>$tab],$filters));}
function ap_number($v,$precision=2) {return $v===null?'Sem dados':number_format((float)$v,$precision,',',' ');}
function ap_value($v,$unit='') {return $v===null||trim((string)$v)===''?'Sem dados':(string)$v.($unit!==''?' '.$unit:'');}
function ap_quantity($v,$unit) {return $v===null?'Sem dados':ap_number($v).' '.($unit?:'(unidade não registada)');}
function ap_date($v) {$t=$v?strtotime($v):false;return $t===false?'Sem dados':date('d/m/Y',$t);}
function ap_fields(array $article,array $fields) {
    echo '<dl class="row cp-details">';
    foreach($fields as $field=>$label){echo '<dt class="col-sm-5">'.h($label).'</dt><dd class="col-sm-7">'.h(ap_value($article[$field]??null)).'</dd>';}
    echo '</dl>';
}
$documents=in_array($tab,['overview','documents'],true)?$service->documents($id):[];
if(($_GET['action']??'')==='export'){
    if(!gt_erp_user_can($pdo,$user,'erp.reports_export')){http_response_code(403);exit('Sem permissão para exportar.');}
    require_once __DIR__.'/app/Services/ProductionDossierService.php';
    $snapshot=(new ProductionDossierService($pdo))->articleSnapshot($id);
    $articleProfileSheet=true;$sheet=['finished_product_id'=>$id,'snapshot_json'=>json_encode($snapshot,JSON_UNESCAPED_UNICODE),'created_at'=>$article['updated_at']??$article['created_at']];
    require __DIR__.'/erp_technical_sheet.php';exit;
}
$summary=$service->summary($id,$financial);$data=[];$routing=null;$materials=[];$versions=[];$colors=[];$features=[];
if($tab==='overview'){$data=$service->history($id,$filters,'overview',5);if($canRouting)$routing=['active'=>$service->activeRouting($id)];}
if(in_array($tab,['history','costs','trace'],true))$data=$service->history($id,$filters,$tab);
if($tab==='routing')$routing=$service->routing($id,is_scalar($_GET['version']??null)?(int)($_GET['version']??0):0);
if($tab==='technical'){$materials=$service->materials($id);$versions=$service->technicalVersions($id);$colors=$service->colors($id);$features=$service->features($id);}
$historicalVersion=$tab==='technical'&&is_scalar($_GET['technical_version']??null)?$service->technicalVersion($id,(int)$_GET['technical_version']):null;
$movements=$tab==='trace'?$service->movements($id,$filters,is_scalar($_GET['mp']??null)?(int)$_GET['mp']:1):[];
$reverse=$tab==='trace'?$service->reverseTrace($id,is_scalar($_GET['unit_type']??null)?(string)$_GET['unit_type']:'',is_scalar($_GET['stock_unit']??null)?(int)$_GET['stock_unit']:0):[];
$statuses=$tab==='history'||$tab==='costs'||$tab==='trace'?$service->options($id):[];
$pageTitle='Ficha de artigo';$navbarClockControl=[];require __DIR__.'/partials/header.php';
?>
<link rel="stylesheet" href="assets/customer-profile.css?v=<?= h((string)filemtime(__DIR__.'/assets/customer-profile.css')) ?>">
<div class="container-fluid py-4 customer-profile">
<header class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
<div><a class="btn btn-sm btn-outline-secondary" href="erp.php?page=articles"><i class="bi bi-arrow-left me-1"></i>Voltar à lista</a><div class="small text-secondary mt-3"><?= h($article['code']) ?></div><h1 class="h3 mb-2"><?= h($article['description']) ?></h1>
<div class="d-flex flex-wrap gap-2 align-items-center"><span class="badge <?= $article['status']==='Ativo'?'text-bg-success':'text-bg-secondary' ?>"><?= h($article['status']) ?></span>
<?php if($article['customer_id']&&$article['customer_name']!==null): ?><a href="erp.php?page=customer_profile&amp;id=<?= (int)$article['customer_id'] ?>"><?= h($article['customer_name']) ?></a><?php else: ?><span class="text-secondary">Sem cliente associado</span><?php endif; ?></div>
<p class="small text-secondary mt-2 mb-0"><?= h(ap_value($article['width'],'cm').' × '.ap_value($article['length'],'cm').' · '.ap_value($article['grammage'],'g/m²').' · Cores por face: '.ap_value($article['colors_per_face'])) ?></p>
<?php if(!empty($article['proof_reference'])): ?><p class="small mt-2 mb-0">Prova: <?= h($article['proof_reference']) ?></p><?php endif; ?></div>
<div class="d-flex flex-wrap gap-2"><?php if(gt_erp_user_can($pdo,$user,'erp.master_data')): ?><a class="btn btn-outline-primary" href="erp.php?page=articles&amp;article_id=<?= $id ?>#article-editor">Editar artigo</a><?php endif; ?><?php if($canRouting): ?><a class="btn btn-outline-success" href="erp_routing.php?article_id=<?= $id ?>">Routing</a><?php endif; ?><?php if(gt_erp_user_can($pdo,$user,'erp.reports_export')): ?><a class="btn btn-outline-secondary" href="<?= h(ap_url($id,'technical',['action'=>'export'])) ?>" target="_blank" rel="noopener">Exportar ficha técnica</a><?php endif; ?></div>
</header>
<div class="row g-3 mb-4">
<?php $cards=[['Total de encomendas','Sem dados','Histórico comercial por integrar'],['Total de ordens de fabrico',ap_value($summary['ofs']),'Relação pelo identificador do artigo'],['Quantidade total produzida',ap_quantity($summary['quantity'],$article['unit']),'Quantidade registada nas OF'],['Última produção',ap_date($summary['last_production']),'Produção encerrada ou fecho com produção'],['Custo real médio de produção',$financial?ap_value($summary['average_cost']===null?null:ap_number($summary['average_cost']),'€ / OF'):'Acesso reservado',$financial?'Média dos totais de '.$summary['closed_ofs'].' OF com fecho e produção':'Requer permissão de custeio'],['Tempo total de produção concluído',ap_value($summary['hours']===null?null:ap_number($summary['hours']),'h'),'Registos encerrados; desconta pausas']];foreach($cards as $card): ?>
<div class="col-12 col-sm-6 col-xl-4"><div class="card h-100"><div class="card-body"><div class="small text-secondary mb-2"><?= h($card[0]) ?></div><div class="h4 cp-metric mb-2"><?= h($card[1]) ?></div><div class="small text-secondary"><?= h($card[2]) ?></div></div></div></div><?php endforeach; ?>
</div>
<nav class="cp-tabs mb-4" aria-label="Separadores da ficha"><div class="d-flex flex-wrap gap-2"><?php foreach($tabs as $key=>$label):if(($key==='costs'&&!$financial)||($key==='routing'&&!$canRouting))continue; ?><a class="btn btn-sm <?= $tab===$key?'btn-primary':'btn-outline-secondary' ?>" <?= $tab===$key?'aria-current="page"':'' ?> href="<?= h(ap_url($id,$key)) ?>"><?= h($label) ?></a><?php endforeach; ?></div></nav>
<section class="card"><div class="card-body"><h2 class="h5 mb-3"><?= h($tabs[$tab]) ?></h2>
<?php if(in_array($tab,['history','costs','trace'],true)): ?>
<form method="get" class="row g-2 mb-4"><input type="hidden" name="page" value="article_profile"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="tab" value="<?= h($tab) ?>">
<?php foreach(['from'=>'Desde','to'=>'Até'] as $key=>$label): ?><div class="col-6 col-md-3"><label class="form-label small" for="<?= $key ?>"><?= h($label) ?></label><input class="form-control" type="date" id="<?= $key ?>" name="<?= $key ?>" value="<?= h($filters[$key]) ?>"></div><?php endforeach; ?>
<div class="col-6 col-md-3"><label class="form-label small" for="status">Estado</label><select class="form-select" id="status" name="status"><option value="">Todos</option><?php foreach($statuses as $r): ?><option value="<?= h($r['status']) ?>" <?= $filters['status']===$r['status']?'selected':'' ?>><?= h($r['status']) ?></option><?php endforeach; ?></select></div><div class="col-6 col-md-3 align-self-end"><button class="btn btn-primary w-100">Filtrar</button></div></form>
<?php endif; ?>
<?php if($tab==='overview'): ?>
<div class="row g-3 mb-4"><div class="col-md-6"><?php ap_fields($article,['code'=>'Código','description'=>'Descrição','status'=>'Estado','composition'=>'Composição','material'=>'Material','bag_color'=>'Cor','width'=>'Largura (cm)','length'=>'Comprimento (cm)','grammage'=>'Gramagem (g/m²)','seam_type'=>'Costura','perforation_type'=>'Perfuração','gusset_length'=>'Medida do fole']); ?></div><div class="col-md-6"><h3 class="h6">Roteiro ativo</h3><p><?= h(!$canRouting?'Acesso reservado':($routing['active']?'Versão '.$routing['active']['version_no'].' · '.ap_date($routing['active']['effective_from']):'Sem routing ativo.')) ?></p><h3 class="h6">Última atividade produtiva concluída</h3><p><?= h(ap_date($summary['last_production'])) ?></p><a class="btn btn-sm btn-outline-primary" href="<?= h(ap_url($id,'technical')) ?>">Consultar todos os dados técnicos</a></div></div><h3 class="h6">Últimas ordens de fabrico</h3>
<?php elseif($tab==='technical'): ?>
<?php $groups=[
'Dimensões e composição'=>['width'=>'Largura (cm)','length'=>'Comprimento (cm)','grammage'=>'Gramagem (g/m²)','material'=>'Material','bag_color'=>'Cor','composition'=>'Composição','width_tolerance'=>'Tolerância largura','length_tolerance'=>'Tolerância comprimento'],
'Características de fabrico'=>['seam_type'=>'Tipo de costura','perforation_type'=>'Tipo de perfuração','thread_color'=>'Cor do fio','gusset_length'=>'Medida do fole','pallet_film'=>'Filme','pallet_lid'=>'Tampa','pallet_straps'=>'Número de fitas','lot_identification_rule'=>'Regra de identificação do lote'],
'Peso e paletização'=>['theoretical_weight'=>'Peso teórico (g)','pallet_weight'=>'Peso teórico da palete (kg)','pallet_quantity'=>'Quantidade por palete ('.($article['unit']?:'unidade não registada').')','pallet_dimensions'=>'Medidas da palete'],
'Impressão'=>['colors_per_face'=>'Cores por face','front_colors'=>'Tintas da ficha técnica — frente','back_colors'=>'Tintas da ficha técnica — verso','of_front_colors'=>'Tintas da OF — frente','of_back_colors'=>'Tintas da OF — verso','proof_reference'=>'Referência da prova','proof_status'=>'Estado da prova','printer_roll_measure'=>'Rolo impressor'],
'Boletim de Análise'=>['analysis_grammage'=>'Gramagem média','analysis_total_weight'=>'Peso total','analysis_apparent_width'=>'Largura aparente','analysis_gusset_width'=>'Largura do fole','analysis_bag_height'=>'Altura do saco','analysis_break_height'=>'Rotura / alongamento altura','analysis_break_length'=>'Rotura / alongamento comprimento','analysis_seam_strength'=>'Resistência da costura','analysis_static_friction'=>'Fricção estática','analysis_dynamic_friction'=>'Fricção dinâmica','analysis_air_permeability'=>'Permeabilidade do ar'],
'Outros dados'=>['customer_product_code'=>'Código do cliente','min_stock'=>'Stock mínimo','notes'=>'Observações']];
$technical=$article;$technical['theoretical_weight']=ArticleTheoreticalWeight::grams($article['width'],$article['length'],$article['grammage']);$technical['pallet_weight']=ArticlePalletWeight::kilograms($technical['theoretical_weight'],$article['pallet_quantity']??null);
?>
<div class="row g-4"><?php foreach($groups as $title=>$fields): ?><div class="col-lg-6"><h3 class="h6 border-bottom pb-2"><?= h($title) ?></h3><?php if($title==='Boletim de Análise'): ?><p class="small text-secondary">Valores, unidades e normas tal como registados; referências ausentes não são inferidas.</p><?php endif; ?><?php ap_fields($technical,$fields); ?></div><?php endforeach; ?></div>
<h3 class="h6 mt-3">Características do saco</h3><dl class="row cp-details"><?php foreach(['microperforation'=>'Microperfuração','has_handle'=>'Asa','has_holes'=>'Furos','has_gusset'=>'Fole','centered_gusset'=>'Fole centrado','anti_slip_ink'=>'Tinta antiderrapante','anti_slip_mesh'=>'Malha antiderrapante','of_colors_match_technical'=>'Tintas da OF iguais à ficha técnica'] as $field=>$label): ?><dt class="col-sm-5"><?= h($label) ?></dt><dd class="col-sm-7"><?= !array_key_exists($field,$article)||$article[$field]===null?'Sem dados':(!empty($article[$field])?'Sim':'Não') ?></dd><?php endforeach; ?><?php foreach($features as $feature): ?><dt class="col-sm-5"><?= h($feature['feature_key']) ?></dt><dd class="col-sm-7"><?= h(ap_value($feature['feature_value'])) ?></dd><?php endforeach; ?></dl>
<?php if($financial): ?><?php ap_fields($article,['standard_cost'=>'Custo padrão estimado (€)','sale_price'=>'Preço de venda (€)']); ?><?php endif; ?>
<h3 class="h6 mt-3">BOM — consumos previstos por unidade</h3><div class="table-responsive"><table class="table"><thead><tr><th>Material</th><th>Quantidade / unidade de artigo</th><th>Desperdício</th><th>Nota</th></tr></thead><tbody><?php foreach($materials as $r): ?><tr><td><?= h($r['code'].' — '.$r['description']) ?></td><td><?= h(ap_quantity($r['quantity_per_unit'],$r['unit'])) ?></td><td><?= h(ap_number($r['waste_percent']).' %') ?></td><td><?= h($r['notes']) ?></td></tr><?php endforeach; ?><?php if(!$materials): ?><tr><td colspan="4">Sem BOM registada.</td></tr><?php endif; ?></tbody></table></div>
<?php if($colors): ?><h3 class="h6">Cores e referências registadas</h3><ul><?php foreach($colors as $r): ?><li><?= h(ap_value($r['face']).' · '.$r['color_order'].' · '.ap_value($r['color_name']?:$r['ink_type']).' · Pantone: '.ap_value($r['pantone'])) ?></li><?php endforeach; ?></ul><?php endif; ?>
<h3 class="h6 mt-3">Versões da ficha técnica</h3><ul><?php foreach($versions as $v): ?><li><a href="<?= h(ap_url($id,'technical',['technical_version'=>$v['id']])) ?>"><?= h('Versão '.$v['version_no'].' · '.$v['status'].' · '.ap_date($v['effective_from'])) ?></a></li><?php endforeach; ?></ul><?php if(!$versions): ?><p class="text-secondary">Sem versões técnicas registadas.</p><?php endif; ?><p class="small text-secondary">As fichas efetivamente usadas na produção podem ser abertas no histórico de OF.</p>
<?php if($historicalVersion): $historical=json_decode($historicalVersion['snapshot_json'],true)?:[]; ?><div class="border rounded p-3 mt-3"><h3 class="h6">Snapshot histórico · versão <?= (int)$historicalVersion['version_no'] ?></h3><p class="small text-secondary">Valores guardados nesta versão. Campos ausentes não são preenchidos a partir do artigo atual.</p><div class="row g-3"><?php foreach($groups as $title=>$fields): ?><div class="col-lg-6"><h4 class="h6"><?= h($title) ?></h4><?php ap_fields($historical,$fields); ?></div><?php endforeach; ?></div><h4 class="h6">Matérias-primas da versão</h4><ul><?php foreach($historical['_materials']??[] as $m): ?><li><?= h(ap_value($m['code']??null).' · '.ap_quantity($m['quantity_per_unit']??null,$m['unit_code']??null).' · desperdício '.ap_value($m['waste_percent']??null,'%')) ?></li><?php endforeach; ?></ul><?php if(empty($historical['_materials'])): ?><p class="text-secondary">Sem BOM neste snapshot.</p><?php endif; ?></div><?php endif; ?>
<?php elseif($tab==='routing'): ?>
<a class="btn btn-sm btn-outline-success mb-3" href="erp_routing.php?article_id=<?= $id ?>">Gerir routing</a>
<?php if(!$routing['active']): ?><p class="alert alert-info">Este artigo não tem routing ativo.</p><?php endif; ?>
<?php if($routing['versions']): ?><form method="get" class="d-flex flex-wrap gap-2 mb-3"><input type="hidden" name="page" value="article_profile"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="tab" value="routing"><label for="version" class="align-self-center">Histórico de versões</label><select class="form-select cp-search" id="version" name="version"><option value="">Versão ativa</option><?php foreach($routing['versions'] as $v): ?><option value="<?= (int)$v['id'] ?>" <?= $routing['selected']&&(int)$routing['selected']['id']===(int)$v['id']?'selected':'' ?>><?= h($v['routing_name'].' · v'.$v['version_no'].' · '.$v['status'].' · '.ap_date($v['effective_from']?:$v['created_at'])) ?></option><?php endforeach; ?></select><button class="btn btn-primary">Consultar</button></form><?php endif; ?>
<?php if($routing['selected']): ?><p><?= h('Versão '.$routing['selected']['version_no'].' · '.$routing['selected']['status'].' · '.ap_date($routing['selected']['effective_from']?:$routing['selected']['created_at'])) ?></p><?php endif; ?>
<div class="table-responsive"><table class="table align-middle"><thead><tr><th>Ordem / Operação</th><th>Centros / Máquinas</th><th>Operadores</th><th>Preparação</th><th>Ciclo / Cadência</th><th>Quantidade base</th><th>Consumos / unidade</th></tr></thead><tbody><?php foreach($routing['steps'] as $r): $snap=json_decode($r['operation_snapshot_json'],true)?:[];$calculation=['seconds_per_unit'=>'s / unidade','minutes_per_unit'=>'min / unidade','units_per_hour'=>'unidades / h','meters_per_minute'=>'m / min']; ?>
<tr><td><?= (int)$r['sort_order'] ?> · <?= h($snap['name']??$r['operation_name']??'Operação histórica') ?><div class="small text-secondary"><?= !empty($r['is_active'])?'Ativa':'Inativa' ?></div></td><td><?= h(implode(' · ',$routing['centers'][$r['id']]??array_filter([$r['work_center_name']]))?:'Sem dados') ?><div class="small text-secondary"><?= h(implode(' · ',$routing['machines'][$r['id']]??array_filter([$r['machine_name']]))?:'Sem dados') ?></div></td><td><?= (int)$r['operators_count'] ?></td><td><?= h(ap_number($r['setup_time']).' min') ?></td><td><?= h(ap_number($r['run_value']).' '.($calculation[$r['calculation_unit']]??$r['calculation_unit'])) ?></td><td><?= h(ap_quantity($r['base_quantity'],$article['unit'])) ?></td><td><?php foreach($routing['materials'][$r['id']]??[] as $m): ?><div><?= h($m['code'].' — '.$m['description'].' · '.ap_quantity($m['quantity_per_unit'],$m['unit'])) ?></div><?php endforeach; ?><?php if(empty($routing['materials'][$r['id']])): ?>Sem consumos associados<?php endif; ?></td></tr><?php endforeach; ?><?php if(!$routing['steps']): ?><tr><td colspan="7">Sem operações nesta versão.</td></tr><?php endif; ?></tbody></table></div>
<?php elseif($tab==='history'): ?><h3 class="h6">Encomendas</h3><p class="text-secondary">Sem dados de encomendas comerciais ou quantidades entregues. As encomendas existentes são compras a fornecedores. O histórico Bobinas será apresentado quando houver relações comprovadas com o artigo.</p><h3 class="h6 mt-4">Ordens de fabrico</h3>
<?php elseif($tab==='costs'): ?><p class="small text-secondary">Totais previstos e reais são os do fecho da OF. Os custos por categoria são apresentados separadamente e não somados novamente ao fecho. Os tempos previstos usam o snapshot do routing; os reais usam registos encerrados, descontando pausas. Tarifas atuais e preço de venda não são usados.</p>
<?php elseif($tab==='trace'): ?><p class="small text-secondary">Artigo → OF → consumos → lotes documentados. Reservas são identificadas separadamente dos consumos efetivos. Lotes sem identificador são indicados como não registados.</p><?php if(!empty($data['truncated'])): ?><p class="alert alert-info">Resumo limitado a 200 consumos e 200 rolos por página. Abra a OF para consultar os detalhes completos.</p><?php endif; ?>
<?php endif; ?>
<?php if(in_array($tab,['overview','history','costs','trace'],true)): require __DIR__.'/partials/article-profile-history.php'; endif; ?>
<?php if($tab==='overview'||$tab==='documents'): ?>
<h3 class="h6 mt-4">Documentação técnica<?= $tab==='overview'?' principal':'' ?></h3>
<?php if(!$documents): ?><p class="text-secondary">Sem documentos associados a este artigo.</p><?php else: ?><div class="table-responsive"><table class="table"><thead><tr><th>Documento</th><th>Tipo / Versão</th><th>Estado</th><th>Abrir / Descarregar</th></tr></thead><tbody><?php foreach($documents as $doc): ?><tr><td><?= h($doc['title']) ?><?php if($doc['document_type']==='production_main'): ?><span class="badge text-bg-primary ms-2">Maquete atual</span><?php endif; ?></td><td><?= h($doc['document_type'].' · '.ap_value($doc['version'])) ?></td><td><?= h($doc['status']) ?></td><td><?php if(!empty($doc['file_url'])): ?><a class="btn btn-sm btn-outline-primary" href="<?= h(ArticleDocument::url((int)$doc['id'])) ?>" target="_blank" rel="noopener">Abrir / Descarregar</a><?php else: ?>Ficheiro não registado<?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
<?php if($tab==='documents'): $latestSheet=$service->latestSheet($id); ?><a class="btn btn-sm btn-outline-secondary" href="<?= h(ap_url($id,'history')) ?>">Fichas técnicas usadas nas OF</a><?php if($latestSheet): ?><a class="btn btn-sm btn-outline-secondary" href="erp_technical_sheet.php?id=<?= (int)$latestSheet['id'] ?>" target="_blank" rel="noopener">Última ficha técnica de OF · <?= h(ap_date($latestSheet['created_at'])) ?></a><?php endif; ?><?php endif; ?>
<?php endif; ?>
</div></section></div>
<?php require __DIR__.'/partials/footer.php'; ?>
