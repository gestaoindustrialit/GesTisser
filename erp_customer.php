<?php
// Customer profile: bypass legacy migrations/status writes; only explicit, CSRF-checked saves may write.
function db() {return $GLOBALS['pdo'];}
function has_shopfloor_only_navigation(array $user): bool {
    return (int)($user['is_admin']??0)!==1 && ((int)($user['pin_only_login']??0)===1 || (string)($user['access_profile']??'')==='Utilizador');
}
require_once __DIR__.'/bootstrap/app.php';
require_once __DIR__.'/erp_migrations.php';
require_once __DIR__.'/app/Services/CustomerProfile.php';
$path=app_config('db_path');
if(!is_file($path)){http_response_code(503);exit('Base de dados indisponível.');}
$pdo=new PDO('sqlite:'.$path);$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$pdo->exec('PRAGMA foreign_keys=ON');$pdo->exec('PRAGMA query_only=ON');
require_login();$user=current_user($pdo)?:[];
if(!gt_erp_user_can($pdo,$user,'erp.view') || !gt_erp_user_can($pdo,$user,'erp.customers')){http_response_code(403);exit('Sem permissão para consultar clientes.');}
$method=$_SERVER['REQUEST_METHOD']??'GET';
if(!in_array($method,['GET','POST'],true)){http_response_code(405);header('Allow: GET, POST');exit('Método não permitido.');}
$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT)?:0;
if($id<1){http_response_code(404);exit('Cliente não encontrado.');}
$service=new CustomerProfile($pdo);$customer=$service->customer($id);
if(!$customer){http_response_code(404);exit('Cliente não encontrado.');}
$tabs=['overview'=>'Visão geral','orders'=>'Encomendas','articles'=>'Artigos','ofs'=>'Ordens de fabrico','costs'=>'Histórico / Custeio','trace'=>'Rastreabilidade','general'=>'Dados gerais'];
$tab=is_scalar($_GET['tab']??'')?(string)($_GET['tab']??'overview'):'overview';if(!isset($tabs[$tab]))$tab='overview';
$editing=$method==='POST'||($_GET['edit']??'')==='1';
if($editing)$tab='general';
$flashError='';
$financial=gt_erp_user_can($pdo,$user,'erp.costs_view');
if($tab==='costs' && !$financial){http_response_code(403);exit('Sem permissão para consultar custos.');}
$filters=CustomerProfile::filters($_GET);
function cp_url($id,$tab,array $filters=[]) {return 'erp.php?'.http_build_query(array_merge(['page'=>'customer_profile','id'=>$id,'tab'=>$tab],$filters));}
function cp_number($value,$precision=2){return $value===null?'Sem dados':number_format((float)$value,$precision,',',' ');}
function cp_date($value){$timestamp=$value?strtotime($value):false;return $timestamp===false?'Sem dados':date('d/m/Y',$timestamp);}
function cp_quantity($value,$unit){return $value===null?'Sem dados':cp_number($value).' '.($unit?:'(unidade não registada)');}
if($method==='POST') {
    if(($_POST['action']??'')!=='save_customer'){http_response_code(405);exit('Ação não permitida.');}
    // Reuse the existing token contract without the legacy helper's DB write on a rejected token.
    $token=$_POST['_token']??'';
    if(!is_string($token)||$token===''||!hash_equals(csrf_token(),$token)){http_response_code(419);exit('Pedido inválido. Atualize a página e tente novamente.');}
    $postedId=is_scalar($_POST['customer_id']??null)?filter_var($_POST['customer_id'],FILTER_VALIDATE_INT):false;
    if($postedId!==$id){http_response_code(400);exit('Cliente inválido para esta ficha.');}
    require_once __DIR__.'/app/Services/CustomerEditor.php';
    try {
        $pdo->exec('PRAGMA query_only=OFF');
        $pdo->beginTransaction();
        CustomerEditor::save($pdo,$_POST,(int)$user['id']);
        $pdo->commit();
    } catch(PDOException $e) {
        if($pdo->inTransaction())$pdo->rollBack();
        error_log('Customer profile save failed: '.$e->getMessage());
        $flashError='Não foi possível guardar o cliente. Verifique os dados e se o código já existe.';
        http_response_code(422);
    } catch(RuntimeException $e) {
        if($pdo->inTransaction())$pdo->rollBack();
        $flashError=$e->getMessage();
        http_response_code(422);
    } finally {
        $pdo->exec('PRAGMA query_only=ON');
    }
    if($flashError===''){header('Location: '.cp_url($id,'general',['saved'=>'1']),true,303);exit;}
}
if(($_GET['action']??'')==='export') {
    if(!gt_erp_user_can($pdo,$user,'erp.reports_export')){http_response_code(403);exit('Sem permissão para exportar.');}
    require_once __DIR__.'/app/Services/SimpleXlsx.php';
    $rows=[['Campo','Valor']];
    foreach(['code'=>'Código','name'=>'Nome fiscal','tax_number'=>'NIF','country'=>'País','city'=>'Localidade','address'=>'Morada','address_2'=>'Morada 2','postal_code'=>'Código postal','contact_name'=>'Contacto','phone'=>'Telefone','mobile'=>'Telemóvel','fax'=>'Fax','email'=>'Email','salesperson'=>'Vendedor','discount_percent'=>'Desconto %','balance'=>'Saldo registado (€)','credit_limit'=>'Plafond (€)','notes'=>'Observações'] as $field=>$label)$rows[]=[$label,$customer[$field]??''];
    $rows[]=['Estado',!empty($customer['is_active'])?'Ativo':'Inativo'];
    foreach($service->addresses($id) as $address){
        $rows[]=['Morada de entrega',($address['label']??'').' · '.($address['address']??'').' · '.($address['postal_code']??'').' '.($address['city']??'').' · '.($address['country']??'')];
        $rows[]=['Transportador',$address['transporter']??''];
    }
    $file=SimpleXlsx::create($rows,'Cliente');header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');header('Content-Disposition: attachment; filename="cliente_'.$id.'.xlsx"');header('Cache-Control: no-store');try{readfile($file);}finally{unlink($file);}exit;
}
$summary=$service->summary($id);$data=[];$options=[];$frequent=[];$addresses=[];
if($tab==='overview'){$data=$service->orders($id,$filters,5);$frequent=$service->frequent($id);}
if($tab==='articles')$data=$service->articles($id,$filters);
if($tab==='ofs'){$data=$service->orders($id,$filters);$options=$service->options($id);}
if($tab==='costs'){$data=$service->costs($id,$filters);$options=$service->options($id);}
if($tab==='trace'){$data=$service->trace($id,$filters);$options=$service->options($id);}
if($tab==='general')$addresses=$service->addresses($id);
$editCustomer=$customer;
if($method==='POST'&&$flashError!==''){
    require_once __DIR__.'/app/Services/CustomerSpreadsheet.php';
    foreach(array_unique(CustomerSpreadsheet::columns()) as $field)if(isset($_POST[$field])&&is_scalar($_POST[$field]))$editCustomer[$field]=(string)$_POST[$field];
    $addresses=[];
    foreach(is_array($_POST['delivery_address']??null)?$_POST['delivery_address']:[] as $index=>$unused){
        $row=[];foreach(['id','label','address','postal_code','city','country','transporter'] as $field){$value=$_POST['delivery_'.$field][$index]??'';$row[$field]=is_scalar($value)?(string)$value:'';}$addresses[]=$row;
    }
}
$pageTitle='Ficha de cliente';$navbarClockControl=[];
require __DIR__.'/partials/header.php';
?>
<link rel="stylesheet" href="assets/customer-profile.css?v=<?= h((string)filemtime(__DIR__.'/assets/customer-profile.css')) ?>"><script defer src="assets/customer-profile.js?v=<?= h((string)filemtime(__DIR__.'/assets/customer-profile.js')) ?>"></script>
<div class="container-fluid py-4 customer-profile">
<?php if($flashError!==''): ?><div class="alert alert-danger" role="alert"><?= h($flashError) ?></div><?php elseif(($_GET['saved']??'')==='1'): ?><div class="alert alert-success" role="status">Cliente atualizado com sucesso.</div><?php endif; ?>
<header class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
<div><a class="btn btn-sm btn-outline-secondary" href="erp.php?page=sales"><i class="bi bi-arrow-left me-1"></i>Voltar à lista</a><div class="small text-secondary mt-3"><?= h($customer['code']) ?></div><h1 class="h3 mb-2"><?= h($customer['name']) ?></h1>
<div class="d-flex flex-wrap gap-2 align-items-center"><span class="badge <?= !empty($customer['is_active'])?'text-bg-success':'text-bg-secondary' ?>"><?= !empty($customer['is_active'])?'Ativo':'Inativo' ?></span><span class="text-secondary">NIF <?= h($customer['tax_number']?:'—') ?> · <?= h($customer['country']?:'—') ?> · <?= h($customer['city']??'') ?></span></div>
<?php if(!empty($customer['contact_name'])): ?><p class="small mt-2 mb-0"><i class="bi bi-person me-1"></i><?= h($customer['contact_name']) ?></p><?php endif; ?></div>
<div class="d-flex flex-wrap gap-2"><div class="form-check form-switch cp-edit-switch"><input class="form-check-input" type="checkbox" role="switch" id="customer-edit-toggle" data-customer-edit-toggle data-edit-url="<?= h(cp_url($id,'general',['edit'=>'1'])) ?>" <?= $editing?'checked':'' ?>><label class="form-check-label" for="customer-edit-toggle"><i class="bi bi-pencil me-1" aria-hidden="true"></i>Editar cliente</label></div><noscript><a class="btn btn-outline-primary" href="<?= h(cp_url($id,'general',['edit'=>'1'])) ?>">Editar dados</a></noscript><button class="btn btn-outline-secondary" disabled title="Ainda não existe formulário de encomendas comerciais a clientes">Nova encomenda</button><?php if(gt_erp_user_can($pdo,$user,'erp.reports_export')): ?><a class="btn btn-outline-success" href="<?= h(cp_url($id,'general',['action'=>'export'])) ?>"><i class="bi bi-file-earmark-excel me-1"></i>Exportar ficha</a><?php endif; ?></div>
</header>
<div class="row g-3 mb-4">
<?php $quantityText=[];foreach($summary['quantities'] as $quantity)$quantityText[]=cp_quantity($quantity['quantity'],$quantity['unit']).(!empty($quantity['unknown_order'])?' · '.$quantity['unknown_order']:'');
$cards=[['Total de encomendas','Sem dados','Sem estrutura comercial'],['Total de OFs',$summary['ofs']===null?'Sem dados':(string)$summary['ofs'],'Relação direta com o cliente'],['Quantidade produzida',$quantityText?implode(' · ',$quantityText):'Sem dados','Separada por unidade; sem conversões'],['Última encomenda','Sem dados','Histórico comercial por integrar'],['Artigos distintos produzidos',$summary['produced_articles']===null?'Sem dados':(string)$summary['produced_articles'],'Artigos atuais com FK e produção registada'],['Tempo de produção concluído',cp_number($summary['hours']).($summary['hours']===null?'':' h'),'Registos encerrados; desconta pausas registadas']];
foreach($cards as $card): ?><div class="col-12 col-sm-6 col-xl-4"><div class="card h-100"><div class="card-body"><div class="small text-secondary mb-2"><?= h($card[0]) ?></div><div class="h4 cp-metric mb-2"><?= h($card[1]) ?></div><div class="small text-secondary"><?= h($card[2]) ?></div></div></div></div><?php endforeach; ?></div>
<nav class="cp-tabs mb-4" aria-label="Separadores da ficha"><div class="d-flex flex-wrap gap-2"><?php $tabIcons=['overview'=>'bi-grid','orders'=>'bi-bag-check','articles'=>'bi-box-seam','ofs'=>'bi-clipboard2-check','costs'=>'bi-graph-up','trace'=>'bi-upc-scan','general'=>'bi-person-vcard'];foreach($tabs as $key=>$label):if($key==='costs'&&!$financial)continue; ?><a class="btn btn-sm <?= $tab===$key?'btn-primary':'btn-outline-secondary' ?>" <?= $tab===$key?'aria-current="page"':'' ?> href="<?= h(cp_url($id,$key)) ?>"><i class="bi <?= h($tabIcons[$key]) ?> me-1" aria-hidden="true"></i><?= h($label) ?></a><?php endforeach; ?></div></nav>
<section class="card"><div class="card-body">
<h2 class="h5 mb-3"><?= h($tabs[$tab]) ?></h2>
<?php if($options): ?><form method="get" class="row g-2 mb-4"><input type="hidden" name="page" value="customer_profile"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="tab" value="<?= h($tab) ?>">
<div class="col-6 col-md-2"><label class="form-label small" for="year">Ano</label><select class="form-select" name="year" id="year"><option value="">Todos</option><?php foreach($options['years'] as $r): ?><option <?= $filters['year']===$r['year']?'selected':'' ?>><?= h($r['year']) ?></option><?php endforeach; ?></select></div>
<div class="col-6 col-md-2"><label class="form-label small" for="status">Estado</label><select class="form-select" name="status" id="status"><option value="">Todos</option><?php foreach($options['statuses'] as $r): ?><option <?= $filters['status']===$r['status']?'selected':'' ?>><?= h($r['status']) ?></option><?php endforeach; ?></select></div>
<div class="col-12 col-md-4"><label class="form-label small" for="article">Artigo</label><select class="form-select" name="article" id="article"><option value="">Todos</option><?php foreach($options['articles'] as $r): ?><option value="<?= (int)$r['id'] ?>" <?= $filters['article']===(int)$r['id']?'selected':'' ?>><?= h($r['code'].' — '.$r['description']) ?></option><?php endforeach; ?></select></div>
<div class="col-6 col-md-2"><label class="form-label small" for="sort">Ordenação</label><select class="form-select" name="sort" id="sort"><?php foreach(['newest'=>'Mais recentes','oldest'=>'Mais antigas','number'=>'Número da OF'] as $key=>$label): ?><option value="<?= $key ?>" <?= $filters['sort']===$key?'selected':'' ?>><?= h($label) ?></option><?php endforeach; ?></select></div><div class="col-6 col-md-2 align-self-end"><button class="btn btn-primary w-100">Filtrar</button></div></form><?php endif; ?>
<?php if($tab==='overview'): ?>
<div class="row g-3 mb-4"><div class="col-md-6"><h3 class="h6">Relação comercial</h3><p class="text-secondary">Ainda não existe histórico de encomendas comerciais associado ao cliente. As compras a fornecedores não são apresentadas nesta ficha.</p></div><div class="col-md-6"><h3 class="h6">Última atividade conhecida</h3><p><?= h(cp_date($summary['last_activity'])) ?></p><p class="small text-secondary">Criação de OF ou fim de um registo de produção, quando disponível.</p></div></div>
<h3 class="h6">Últimas OFs</h3>
<?php elseif($tab==='orders'): ?>
<div class="cp-empty"><i class="bi bi-bag" aria-hidden="true"></i><h3 class="h6 mt-3">Histórico comercial por integrar</h3><p class="text-secondary mb-0">O módulo atual de encomendas regista compras a fornecedores. Não existem encomendas de venda ou linhas comerciais comprovadas para este cliente. A criação de encomendas ficará disponível quando existir um formulário comercial, reutilizando-o com o cliente selecionado.</p></div>
<?php elseif($tab==='articles'): ?>
<form method="get" class="d-flex flex-wrap gap-2 mb-3"><input type="hidden" name="page" value="customer_profile"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="tab" value="articles"><label class="visually-hidden" for="q">Pesquisar referência ou designação</label><input id="q" name="q" value="<?= h($filters['q']) ?>" class="form-control cp-search" placeholder="Referência ou designação"><button class="btn btn-primary">Pesquisar</button></form>
<div class="table-responsive"><table class="table table-hover align-middle"><thead><tr><th>Artigo</th><th>Produções</th><th>Quantidade acumulada</th><th>Última produção / criação da OF</th></tr></thead><tbody><?php foreach($data['rows'] as $r): ?><tr><td><a href="erp.php?page=articles&amp;article_id=<?= (int)$r['id'] ?>#article-editor"><?= h($r['code']) ?></a><div class="small text-secondary"><?= h($r['description']) ?></div></td><td><?= $r['productions']===null?'Sem dados':(int)$r['productions'] ?></td><td><?= h(cp_quantity($r['quantity'],$r['unit'])) ?></td><td><?= h(cp_date($r['last_production'])) ?></td></tr><?php endforeach; ?><?php if(!$data['rows']): ?><tr><td colspan="4" class="text-secondary py-4">Sem artigos associados por relações comprovadas.</td></tr><?php endif; ?></tbody></table></div>
<?php elseif($tab==='general'): ?>
<?php require __DIR__.'/partials/customer-profile-form.php'; ?>
<?php endif; ?>
<?php if(in_array($tab,['overview','ofs','costs','trace'],true)): ?>
<?php if($tab==='trace'&&!empty($data['truncated'])): ?><p class="alert alert-info">Consulta resumida aos primeiros 200 consumos e 200 rolos desta página. Para consultar todos os registos, abra a ficha de cada OF.</p><?php endif; ?>
<?php if($tab==='costs'): ?><p class="small text-secondary">Tempos encerrados e custos efetivamente registados. O total e o custo unitário são apresentados apenas quando existe um fecho de OF. Não se aplicam tarifas atuais ao histórico.</p><?php endif; ?>
<div class="table-responsive"><table class="table table-hover align-middle"><thead><tr><th>OF / Artigo</th><th>Criação</th><th>Planeada</th><th>Produzida</th><th>Estado / Origem</th><?php if($tab==='costs'): ?><th>Tempo encerrado</th><th>Custo total registado</th><th>Custo unitário</th><?php endif; ?></tr></thead><tbody>
<?php foreach($data['rows'] as $r): ?><tr><td><a class="fw-semibold" href="production_dossier.php?id=<?= (int)$r['id'] ?>"><?= h($r['order_number']) ?></a><div class="small text-secondary"><?php if($r['finished_product_id']): ?><a href="erp.php?page=articles&amp;article_id=<?= (int)$r['finished_product_id'] ?>#article-editor"><?= h($r['article_code']) ?></a><?php else: ?><?= h($r['article_code']??'Artigo não associado') ?><?php endif; ?> · <?= h($r['article_name']??'') ?></div></td><td><?= h(cp_date($r['created_at'])) ?></td><td><?= h(cp_quantity($r['planned_quantity'],$r['unit'])) ?></td><td><?= h(cp_quantity($r['produced_quantity'],$r['unit'])) ?></td><td><?= h($r['status']) ?><div class="small text-secondary"><?= $r['legacy_id']!==null?'Histórico Bobinas · '.h($r['legacy_id']):'GesTISSER' ?></div></td>
<?php if($tab==='costs'): $closure=$data['closures'][$r['id']]??null; ?><td><?= h(cp_number($data['times'][$r['id']]??null)) ?><?= isset($data['times'][$r['id']])?' h':'' ?></td><td><?= $closure?h(cp_number($closure['total_actual_cost']).' €'):'Sem dados' ?></td><td><?= $closure && (float)$r['produced_quantity']>0?h(cp_number((float)$closure['total_actual_cost']/(float)$r['produced_quantity'],4).' € / '.($r['unit']?:'unidade não registada')):'Sem dados' ?></td><?php endif; ?></tr>
<?php if($tab==='costs'): ?><tr><td colspan="8"><details><summary class="small">Tempos por etapa e custos registados</summary><ul class="small mt-2"><?php foreach($data['operations'][$r['id']]??[] as $operation): ?><li><?= h($operation['operation_name']) ?>: <?= h(cp_number($operation['hours'])) ?> h</li><?php endforeach; ?><?php foreach($data['costs'][$r['id']]??[] as $cost): ?><li><?= h($cost['category']) ?>: <?= h(cp_number($cost['amount'])) ?> €</li><?php endforeach; ?></ul><?php if(empty($data['operations'][$r['id']])&&empty($data['costs'][$r['id']])): ?><p class="small text-secondary">Sem tempos ou custos por etapa registados.</p><?php endif; ?></details></td></tr><?php endif; ?>
<?php if($tab==='trace'): ?><tr><td colspan="5"><details open><summary class="small">Consumos e ligações documentadas</summary><ul class="small mt-2"><?php foreach($data['consumptions'][$r['id']]??[] as $consumption): ?><li><?= h($consumption['material_code']??'Material sem ligação atual') ?> · <?= h(cp_quantity($consumption['quantity'],$consumption['unit_code'])) ?> · lote <?= h($consumption['lot']?:'não registado') ?> · <?= h(cp_date($consumption['created_at'])) ?><?php if($consumption['stock_unit_id']): ?> · <?= h($consumption['stock_unit_type']) ?> #<?= (int)$consumption['stock_unit_id'] ?><?php endif; ?><?php if($consumption['ink_barcode']): ?> · recipiente <?= h($consumption['ink_barcode']) ?> · lote <?= h($consumption['ink_lot']) ?><?php endif; ?><?php if($consumption['movement_number']): ?> · movimento <?= h($consumption['movement_number']) ?><?php endif; ?></li><?php endforeach; ?><?php foreach($data['rolls'][$r['id']]??[] as $roll): ?><li>Rolo <?= h($roll['barcode']) ?> · lote <?= h($roll['supplier_lot']) ?> · <?= h(cp_number($roll['consumed_metres'])) ?> m / <?= h(cp_number($roll['consumed_weight_kg'])) ?> kg · <?= h(cp_date($roll['created_at'])) ?></li><?php endforeach; ?></ul><?php if(empty($data['consumptions'][$r['id']])&&empty($data['rolls'][$r['id']])): ?><p class="small text-secondary"><?= !empty($data['truncated'])?'Detalhes não carregados neste resumo. Abra a ficha da OF para consultar todos os registos.':'Sem ligações comprovadas a rolos, recipientes, lotes ou movimentos nesta OF.' ?></p><?php endif; ?></details></td></tr><?php endif; ?>
<?php endforeach; ?><?php if(!$data['rows']): ?><tr><td colspan="<?= $tab==='costs'?8:5 ?>" class="text-secondary py-4">Sem OFs associadas<?= $tab==='overview'?'':' para os filtros selecionados' ?>.</td></tr><?php endif; ?></tbody></table></div>
<?php if($tab==='overview'): ?><a href="<?= h(cp_url($id,'ofs')) ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-arrow-right me-1" aria-hidden="true"></i>Ver todas as OFs</a><h3 class="h6 mt-4">Artigos mais frequentes</h3><ul><?php foreach($frequent as $article): ?><li><a href="erp.php?page=articles&amp;article_id=<?= (int)$article['id'] ?>#article-editor"><?= h($article['code'].' — '.$article['description']) ?></a> · <?= (int)$article['productions'] ?> produções</li><?php endforeach; ?></ul><?php if(!$frequent): ?><p class="text-secondary">Sem produções associadas a artigos atuais.</p><?php endif; ?><?php endif; ?>
<?php endif; ?>
<?php if($data && $tab!=='overview'): ?><nav aria-label="Paginação" class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3"><span class="small text-secondary"><?= (int)$data['total'] ?> registos · página <?= (int)$data['page'] ?> / <?= (int)$data['pages'] ?></span><div class="d-flex gap-2"><?php if($data['page']>1): ?><a class="btn btn-sm btn-outline-secondary" href="<?= h(cp_url($id,$tab,array_merge($filters,['p'=>$data['page']-1]))) ?>">Anterior</a><?php endif; ?><?php if($data['page']<$data['pages']): ?><a class="btn btn-sm btn-outline-secondary" href="<?= h(cp_url($id,$tab,array_merge($filters,['p'=>$data['page']+1]))) ?>">Seguinte</a><?php endif; ?></div></nav><?php endif; ?>
</div></section>
</div>
<?php require __DIR__.'/partials/footer.php'; ?>
