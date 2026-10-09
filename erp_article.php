<?php
// Consultation never triggers migrations/status writes; explicit saves use the shared writers.
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
$pdo=new PDO('sqlite:'.$path);$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$pdo->exec('PRAGMA foreign_keys=ON');$pdo->exec('PRAGMA busy_timeout=5000');$pdo->exec('PRAGMA query_only=ON');
require_login();$user=current_user($pdo)?:[];
if(!gt_erp_user_can($pdo,$user,'erp.view')){http_response_code(403);exit('Sem permissão para consultar artigos.');}
$method=$_SERVER['REQUEST_METHOD']??'GET';if(!in_array($method,['GET','POST'],true)){http_response_code(405);header('Allow: GET, POST');exit('Método não permitido.');}
$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT)?:0;
$service=new ArticleProfile($pdo);$article=$id>0?$service->article($id):null;
if(!$article){http_response_code(404);exit('Artigo não encontrado.');}
$financial=gt_erp_user_can($pdo,$user,'erp.costs_view');$canRouting=gt_erp_user_can($pdo,$user,'erp.routings.view');
$tabs=['overview'=>'Visão Geral','technical'=>'Dados Técnicos','routing'=>'Routing','history'=>'Encomendas / OF','costs'=>'Histórico / Custeio','trace'=>'Rastreabilidade','documents'=>'Documentos'];
$tab=is_scalar($_GET['tab']??'')?(string)($_GET['tab']??'overview'):'overview';if(!isset($tabs[$tab]))$tab='overview';
if(($tab==='costs'&&!$financial)||($tab==='routing'&&!$canRouting)){http_response_code(403);exit('Sem permissão para consultar este separador.');}
$filters=ArticleProfile::filters($_GET);
$editing=($_GET['edit']??'')==='1';$flashError='';if($editing){if(!gt_erp_user_can($pdo,$user,'erp.master_data')){http_response_code(403);exit('Sem permissão para editar artigos.');}$tab='technical';}

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
// Validate the action, permission, CSRF and article relationship before allowing writes.
$routingActions=['new_version','activate','save_template','apply_template','add_step','update_step','delete_step','delete_version','move'];
$action=is_scalar($_POST['action']??null)?(string)$_POST['action']:'';
if($method==='POST'){
    $chunk=($_GET['article_upload']??'')==='chunk';$isRouting=$tab==='routing'&&in_array($action,$routingActions,true);
    if(!$chunk&&!$isRouting&&!in_array($action,['save_article','duplicate_article','delete_article_document'],true)){http_response_code(405);exit('Ação não permitida.');}
    $permission=$isRouting?($action==='activate'?'erp.routings.activate':'erp.routings.edit'):'erp.master_data';
    if(!gt_erp_user_can($pdo,$user,$permission)){http_response_code(403);exit('Sem permissão para esta ação.');}
    $token=$_POST['_token']??'';if(!is_string($token)||$token===''||!hash_equals(csrf_token(),$token)){http_response_code(419);exit('Pedido inválido. Atualize a página e tente novamente.');}
    if(!$chunk){$posted=is_scalar($_POST['article_id']??null)?filter_var($_POST['article_id'],FILTER_VALIDATE_INT):false;if($posted!==$id){http_response_code(400);exit('Artigo inválido para esta ficha.');}}
    if($chunk){
        require_once __DIR__.'/app/Services/ArticleDocumentChunkUpload.php';header('Content-Type: application/json');
        try{$complete=ArticleDocumentChunkUpload::receive((int)$user['id'],$_POST,$_FILES['chunk']??[]);echo json_encode(['ok'=>true,'complete'=>$complete]);}catch(Throwable $e){http_response_code(422);echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);}exit;
    }
    if($isRouting){
        foreach(['version_id','source_version_id'] as $field){if(!isset($_POST[$field]))continue;$value=is_scalar($_POST[$field])?filter_var($_POST[$field],FILTER_VALIDATE_INT):false;if($field==='source_version_id'&&$value===0)continue;
            $check=$pdo->prepare('SELECT 1 FROM erp_article_routing_versions v JOIN erp_article_routings r ON r.id=v.routing_id WHERE v.id=? AND r.finished_product_id=?');$check->execute([$value,$id]);if(!$check->fetchColumn()){http_response_code(400);exit('Versão de outro artigo ou inexistente.');}}
        if(isset($_POST['step_id'])){$value=is_scalar($_POST['step_id'])?filter_var($_POST['step_id'],FILTER_VALIDATE_INT):false;$check=$pdo->prepare('SELECT 1 FROM erp_article_routing_steps s JOIN erp_article_routing_versions v ON v.id=s.routing_version_id JOIN erp_article_routings r ON r.id=v.routing_id WHERE s.id=? AND r.finished_product_id=?');$check->execute([$value,$id]);if(!$check->fetchColumn()){http_response_code(400);exit('Operação de outro artigo ou inexistente.');}}
    }else{
        require_once __DIR__.'/app/Services/ArticleEditor.php';$input=$_POST;if($action==='delete_article_document')$input['document_id']=$_POST['document_id']??$_GET['document_id']??0;
        try{$pdo->exec('PRAGMA query_only=OFF');$pdo->beginTransaction();
            if($action==='save_article')$result=ArticleEditor::save($pdo,$input,(int)$user['id']);
            elseif($action==='duplicate_article')$result=ArticleEditor::duplicate($pdo,$input,(int)$user['id']);
            else $result=ArticleEditor::deleteDocument($pdo,$input,(int)$user['id']);
            $pdo->commit();$target=ap_url($result['id'],'technical',['saved'=>'1']);if($action==='duplicate_article')$target=ap_url($result['id'],'technical',['edit'=>'1','duplicated'=>'1']);
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log('Article profile save failed: '.$e->getMessage());$flashError=$e instanceof PDOException?'Não foi possível guardar o artigo. Verifique os dados e se o código já existe.':$e->getMessage();http_response_code(422);$editing=true;$tab='technical';}
        finally{$pdo->exec('PRAGMA query_only=ON');}
        if($flashError===''){header('Location: '.$target,true,303);exit;}
    }
}
function ap_routing_markup(PDO $pdo,array $user,$id){
    $embeddedRouting=true;$profileArticleId=$id;ob_start();
    try{if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){$pdo->exec('PRAGMA query_only=OFF');$pdo->beginTransaction();}
        require __DIR__.'/erp_routing.php';if($pdo->inTransaction())$pdo->commit();return ob_get_clean();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();ob_end_clean();throw $e;}
    finally{$pdo->exec('PRAGMA query_only=ON');}
}
$routingMarkup=$tab==='routing'?ap_routing_markup($pdo,$user,$id):'';

$documents=$service->documents($id);$mainArtwork=null;
foreach($documents as $document){if(($document['document_type']??'')==='production_main'&&($document['status']??'')==='Ativo'&&in_array(ArticleDocument::presentation((string)($document['file_url']??''))['kind'],['pdf','image'],true)){$mainArtwork=$document;break;}}
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

if($tab==='technical'){$materials=$service->materials($id);$versions=$service->technicalVersions($id);$colors=$service->colors($id);$features=$service->features($id);}
$historicalVersion=$tab==='technical'&&is_scalar($_GET['technical_version']??null)?$service->technicalVersion($id,(int)$_GET['technical_version']):null;
$movements=$tab==='trace'?$service->movements($id,$filters,is_scalar($_GET['mp']??null)?(int)$_GET['mp']:1):[];
$reverse=$tab==='trace'?$service->reverseTrace($id,is_scalar($_GET['unit_type']??null)?(string)$_GET['unit_type']:'',is_scalar($_GET['stock_unit']??null)?(int)$_GET['stock_unit']:0):[];
$statuses=$tab==='history'||$tab==='costs'||$tab==='trace'?$service->options($id):[];
if($editing){
    require_once __DIR__.'/app/Services/ArticleFormSupport.php';$selectedArticle=$article;$articleFormBackUrl=ap_url($id,'technical');
    if($method==='POST'&&$flashError!==''){foreach($_POST as $field=>$value){if(array_key_exists($field,$selectedArticle)&&is_scalar($value))$selectedArticle[$field]=$value;}foreach(['front_colors','back_colors','of_front_colors','of_back_colors'] as $field)if(isset($_POST[$field])&&is_array($_POST[$field]))$selectedArticle[$field]=$_POST[$field];}
    $customers=$pdo->query('SELECT id,name FROM erp_customers ORDER BY name')->fetchAll();$units=$pdo->query('SELECT id,code,name FROM erp_units ORDER BY code')->fetchAll();
    $rawMaterials=$pdo->query('SELECT r.*,u.code unit_code FROM erp_raw_materials r LEFT JOIN erp_units u ON u.id=r.primary_unit_id ORDER BY r.code')->fetchAll();$inkMaterials=array_values(array_filter($rawMaterials,function($m){return $m['product_category']==='subsidiary'&&$m['status']==='Ativo';}));
    $q=$pdo->prepare('SELECT * FROM erp_article_materials WHERE finished_product_id=? ORDER BY id');$q->execute([$id]);$articleMaterials=$q->fetchAll();
    if($method==='POST'&&$flashError!==''&&$action==='save_article'){$articleMaterials=[];foreach(is_array($_POST['material_id']??null)?$_POST['material_id']:[] as $index=>$materialId){$item=[];foreach(['raw_material_id'=>'material_id','quantity_per_unit'=>'material_quantity','waste_percent'=>'material_waste','notes'=>'material_notes'] as $field=>$key){$value=$_POST[$key][$index]??'';$item[$field]=is_scalar($value)?$value:'';}$articleMaterials[]=$item;}foreach(['of_colors_match_technical','microperforation','has_handle','has_holes','has_gusset','centered_gusset'] as $field)$selectedArticle[$field]=isset($_POST[$field])?1:0;}
$articleDocuments=array_values(array_filter($service->documents($id),function($d){return $d['status']==='Ativo';}));
}
$pageTitle='Ficha de artigo';$navbarClockControl=[];require __DIR__.'/partials/header.php';
?>
<link rel="stylesheet" href="assets/customer-profile.css?v=<?= h((string)filemtime(__DIR__.'/assets/customer-profile.css')) ?>">
<div class="container-fluid py-4 customer-profile article-profile">
<?php if($flashError!==''): ?><div class="alert alert-danger" role="alert"><?= h($flashError) ?></div><?php elseif(($_GET['duplicated']??'')==='1'): ?><div class="alert alert-success" role="status">Artigo duplicado. Reveja os dados da cópia.</div><?php elseif(($_GET['saved']??'')==='1'): ?><div class="alert alert-success" role="status">Artigo atualizado com sucesso.</div><?php endif; ?>
<?php if($editing): ?><script defer src="assets/article-editor.js?v=<?= h((string)filemtime(__DIR__.'/assets/article-editor.js')) ?>"></script><?php endif; ?>
<header class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
<div><a class="btn btn-sm btn-outline-secondary" href="erp.php?page=articles"><i class="bi bi-arrow-left me-1"></i>Voltar à lista</a><div class="small text-secondary mt-3"><?= h($article['code']) ?></div><h1 class="h3 mb-2"><?= h($article['description']) ?></h1>
<div class="d-flex flex-wrap gap-2 align-items-center"><span class="badge <?= $article['status']==='Ativo'?'text-bg-success':'text-bg-secondary' ?>"><?= h($article['status']) ?></span>
<?php if($article['customer_id']&&$article['customer_name']!==null): ?><a href="erp.php?page=customer_profile&amp;id=<?= (int)$article['customer_id'] ?>"><?= h($article['customer_name']) ?></a><?php else: ?><span class="text-secondary">Sem cliente associado</span><?php endif; ?></div>
<p class="small text-secondary mt-2 mb-0"><?= h(ap_value($article['width'],'cm').' × '.ap_value($article['length'],'cm').' · '.ap_value($article['grammage'],'g/m²').' · Cores por face: '.ap_value($article['colors_per_face'])) ?></p>
<?php if(!empty($article['proof_reference'])): ?><p class="small mt-2 mb-0">Prova: <?= h($article['proof_reference']) ?></p><?php endif; ?></div>
<div class="d-flex flex-wrap gap-2"><?php if(gt_erp_user_can($pdo,$user,'erp.master_data')): ?><a class="btn btn-outline-primary" href="<?= h(ap_url($id,'technical',['edit'=>'1'])) ?>"><i class="bi bi-pencil me-1" aria-hidden="true"></i>Editar artigo</a><?php endif; ?><?php if(gt_erp_user_can($pdo,$user,'erp.master_data')): ?><form method="post" action="<?= h(ap_url($id)) ?>"><?= csrf_input() ?><input type="hidden" name="article_id" value="<?= $id ?>"><button class="btn btn-outline-secondary" name="action" value="duplicate_article"><i class="bi bi-files me-1" aria-hidden="true"></i>Duplicar artigo</button></form><?php endif; ?><?php if($canRouting): ?><a class="btn btn-outline-success" href="<?= h(ap_url($id,'routing')) ?>"><i class="bi bi-diagram-3 me-1" aria-hidden="true"></i>Routing</a><?php endif; ?><?php if(gt_erp_user_can($pdo,$user,'erp.reports_export')): ?><a class="btn btn-outline-secondary" href="<?= h(ap_url($id,'technical',['action'=>'export'])) ?>" target="_blank" rel="noopener"><i class="bi bi-file-earmark-arrow-down me-1" aria-hidden="true"></i>Exportar ficha técnica</a><?php endif; ?></div>
</header>
<div class="ap-summary mb-4">
<div class="card ap-artwork"><div class="card-body">
<?php if($mainArtwork): ?><a class="ap-artwork-link" href="<?= h(ArticleDocument::url((int)$mainArtwork['id'])) ?>" target="_blank" rel="noopener" aria-label="<?= h('Abrir maquete principal de '.$article['description']) ?>"><img src="<?= h(ArticleDocument::thumbnailUrl((int)$mainArtwork['id'])) ?>" alt="<?= h('Maquete principal — '.$article['description']) ?>" width="360" height="240"><span class="small"><i class="bi bi-file-earmark-image me-1" aria-hidden="true"></i>Maquete principal<i class="bi bi-box-arrow-up-right ms-2" aria-hidden="true"></i></span></a>
<?php else: ?><div class="ap-artwork-empty text-secondary"><i class="bi bi-file-earmark-image" aria-hidden="true"></i><span class="small">Sem maquete principal</span></div><?php endif; ?>
</div></div>
<div class="ap-metrics">
<?php $cards=[['Total de encomendas','Sem dados','Histórico comercial por integrar'],['Total de ordens de fabrico',ap_value($summary['ofs']),'Relação pelo identificador do artigo'],['Quantidade total produzida',ap_quantity($summary['quantity'],$article['unit']),'Quantidade registada nas OF'],['Última produção',ap_date($summary['last_production']),'Produção encerrada ou fecho com produção'],['Custo real médio de produção',$financial?ap_value($summary['average_cost']===null?null:ap_number($summary['average_cost']),'€ / OF'):'Acesso reservado',$financial?'Média dos totais de '.$summary['closed_ofs'].' OF com fecho e produção':'Requer permissão de custeio'],['Tempo total de produção concluído',ap_value($summary['hours']===null?null:ap_number($summary['hours']),'h'),'Registos encerrados; desconta pausas']];foreach($cards as $card): ?>
<div class="card h-100"><div class="card-body"><div class="small text-secondary mb-2"><?= h($card[0]) ?></div><div class="h4 cp-metric mb-2"><?= h($card[1]) ?></div><div class="small text-secondary"><?= h($card[2]) ?></div></div></div><?php endforeach; ?>
</div></div>
<nav class="cp-tabs mb-4" aria-label="Separadores da ficha"><div class="d-flex flex-wrap gap-2"><?php $tabIcons=['overview'=>'bi-grid','technical'=>'bi-card-list','routing'=>'bi-diagram-3','history'=>'bi-clipboard2-check','costs'=>'bi-graph-up','trace'=>'bi-upc-scan','documents'=>'bi-folder2-open'];foreach($tabs as $key=>$label):if(($key==='costs'&&!$financial)||($key==='routing'&&!$canRouting))continue; ?><a class="btn btn-sm <?= $tab===$key?'btn-primary':'btn-outline-secondary' ?>" <?= $tab===$key?'aria-current="page"':'' ?> href="<?= h(ap_url($id,$key)) ?>"><i class="bi <?= h($tabIcons[$key]) ?> me-1" aria-hidden="true"></i><?= h($label) ?></a><?php endforeach; ?></div></nav>
<section class="card"><div class="card-body"><h2 class="h5 mb-3"><?= h($tabs[$tab]) ?></h2>
<?php if(in_array($tab,['history','costs','trace'],true)): ?>
<form method="get" class="row g-2 mb-4"><input type="hidden" name="page" value="article_profile"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="tab" value="<?= h($tab) ?>">
<?php foreach(['from'=>'Desde','to'=>'Até'] as $key=>$label): ?><div class="col-6 col-md-3"><label class="form-label small" for="<?= $key ?>"><?= h($label) ?></label><input class="form-control" type="date" id="<?= $key ?>" name="<?= $key ?>" value="<?= h($filters[$key]) ?>"></div><?php endforeach; ?>
<div class="col-6 col-md-3"><label class="form-label small" for="status">Estado</label><select class="form-select" id="status" name="status"><option value="">Todos</option><?php foreach($statuses as $r): ?><option value="<?= h($r['status']) ?>" <?= $filters['status']===$r['status']?'selected':'' ?>><?= h($r['status']) ?></option><?php endforeach; ?></select></div><div class="col-6 col-md-3 align-self-end"><button class="btn btn-primary w-100"><i class="bi bi-funnel me-1" aria-hidden="true"></i>Filtrar</button></div></form>
<?php endif; ?>
<?php if($tab==='technical'&&$editing): require __DIR__.'/partials/article-profile-form.php'; ?>
<?php elseif($tab==='overview'): ?>
<div class="row g-3 mb-4"><div class="col-md-6"><?php ap_fields($article,['code'=>'Código','description'=>'Descrição','status'=>'Estado','composition'=>'Composição','material'=>'Material','bag_color'=>'Cor','width'=>'Largura (cm)','length'=>'Comprimento (cm)','grammage'=>'Gramagem (g/m²)','seam_type'=>'Costura','perforation_type'=>'Perfuração','gusset_length'=>'Medida do fole']); ?></div><div class="col-md-6"><h3 class="h6">Roteiro ativo</h3><p><?= h(!$canRouting?'Acesso reservado':($routing['active']?'Versão '.$routing['active']['version_no'].' · '.ap_date($routing['active']['effective_from']):'Sem routing ativo.')) ?></p><h3 class="h6">Última atividade produtiva concluída</h3><p><?= h(ap_date($summary['last_production'])) ?></p><a class="btn btn-sm btn-outline-primary" href="<?= h(ap_url($id,'technical')) ?>"><i class="bi bi-card-list me-1" aria-hidden="true"></i>Consultar todos os dados técnicos</a></div></div><h3 class="h6">Últimas ordens de fabrico</h3>
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
<?= $routingMarkup ?>
<?php elseif($tab==='history'): ?><h3 class="h6">Encomendas</h3><p class="text-secondary">Sem dados de encomendas comerciais ou quantidades entregues. As encomendas existentes são compras a fornecedores. O histórico Bobinas será apresentado quando houver relações comprovadas com o artigo.</p><h3 class="h6 mt-4">Ordens de fabrico</h3>
<?php elseif($tab==='costs'): ?><p class="small text-secondary">Totais previstos e reais são os do fecho da OF. Os custos por categoria são apresentados separadamente e não somados novamente ao fecho. Os tempos previstos usam o snapshot do routing; os reais usam registos encerrados, descontando pausas. Tarifas atuais e preço de venda não são usados.</p>
<?php elseif($tab==='trace'): ?><p class="small text-secondary">Artigo → OF → consumos → lotes documentados. Reservas são identificadas separadamente dos consumos efetivos. Lotes sem identificador são indicados como não registados.</p><?php if(!empty($data['truncated'])): ?><p class="alert alert-info">Resumo limitado a 200 consumos e 200 rolos por página. Abra a OF para consultar os detalhes completos.</p><?php endif; ?>
<?php endif; ?>
<?php if(in_array($tab,['overview','history','costs','trace'],true)): require __DIR__.'/partials/article-profile-history.php'; endif; ?>
<?php if($tab==='overview'||$tab==='documents'): ?>
<h3 class="h6 mt-4">Documentação técnica<?= $tab==='overview'?' principal':'' ?></h3>
<?php if(!$documents): ?><p class="text-secondary">Sem documentos associados a este artigo.</p><?php else: ?><div class="table-responsive"><table class="table"><thead><tr><th>Documento</th><th>Tipo / Versão</th><th>Estado</th><th>Abrir / Descarregar</th></tr></thead><tbody><?php foreach($documents as $doc): ?><tr><td><?= h($doc['title']) ?><?php if($doc['document_type']==='production_main'): ?><span class="badge text-bg-primary ms-2">Maquete atual</span><?php endif; ?></td><td><?= h($doc['document_type'].' · '.ap_value($doc['version'])) ?></td><td><?= h($doc['status']) ?></td><td><?php if(!empty($doc['file_url'])): ?><a class="btn btn-sm btn-outline-primary" href="<?= h(ArticleDocument::url((int)$doc['id'])) ?>" target="_blank" rel="noopener"><i class="bi bi-file-earmark-arrow-down me-1" aria-hidden="true"></i>Abrir / Descarregar</a><?php else: ?>Ficheiro não registado<?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
<?php if($tab==='documents'): $latestSheet=$service->latestSheet($id); ?><a class="btn btn-sm btn-outline-secondary" href="<?= h(ap_url($id,'history')) ?>"><i class="bi bi-clipboard2-check me-1" aria-hidden="true"></i>Fichas técnicas usadas nas OF</a><?php if($latestSheet): ?><a class="btn btn-sm btn-outline-secondary" href="erp_technical_sheet.php?id=<?= (int)$latestSheet['id'] ?>" target="_blank" rel="noopener"><i class="bi bi-file-earmark-text me-1" aria-hidden="true"></i>Última ficha técnica de OF · <?= h(ap_date($latestSheet['created_at'])) ?></a><?php endif; ?><?php endif; ?>
<?php endif; ?>
</div></section></div>
<?php require __DIR__.'/partials/footer.php'; ?>
