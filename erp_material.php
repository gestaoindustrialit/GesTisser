<?php
// Dispatch before legacy config.php: consultation must never run migrations or stock writes.
function db() { return $GLOBALS['pdo']; }
function has_shopfloor_only_navigation(array $user): bool {
    return (int)($user['is_admin'] ?? 0) !== 1 && ((int)($user['pin_only_login'] ?? 0) === 1 || (string)($user['access_profile'] ?? '') === 'Utilizador');
}
require_once __DIR__.'/bootstrap/app.php';
require_once __DIR__.'/erp_migrations.php';
require_once __DIR__.'/material_profile_service.php';
$path = app_config('db_path');
if (!is_file($path)) { http_response_code(503); exit('Base de dados indisponível.'); }
$pdo = new PDO('sqlite:'.$path);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA query_only=ON');
require_login();
$user = current_user($pdo) ?: [];
if (!gt_erp_user_can($pdo,$user,'erp.view')) { http_response_code(403); exit('Sem permissão para consultar materiais.'); }
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') { http_response_code(405); header('Allow: GET'); exit('Método não permitido.'); }
$id = isset($_GET['id']) && is_scalar($_GET['id']) ? filter_var($_GET['id'], FILTER_VALIDATE_INT) : false;
if (!$id || $id < 1) { http_response_code(404); exit('Material não encontrado.'); }
$service = new MaterialProfile($pdo); $material = $service->material($id);
if (!$material) { http_response_code(404); exit('Material não encontrado.'); }
$tabs = ['overview'=>'Visão geral','technical'=>'Dados técnicos','stock'=>'Stocks e movimentos','lots'=>'Lotes e rastreabilidade','consumptions'=>'Consumos / OF','suppliers'=>'Fornecedores e custos','documents'=>'Documentos'];
$tab = isset($_GET['tab']) && is_scalar($_GET['tab']) ? (string)$_GET['tab'] : 'overview';
if (!isset($tabs[$tab])) $tab = 'overview';
try { $filters = MaterialProfile::filters($_GET); }
catch (InvalidArgumentException $e) { http_response_code(400); exit($e->getMessage()); }
$canCosts = gt_erp_user_can($pdo,$user,'erp.costs_view');
$summary = $service->summary($id,$filters);
function mp_url($id,$tab,array $filters = []) { return 'erp.php?'.http_build_query(array_merge(['page'=>'material_profile','id'=>$id,'tab'=>$tab],$filters)); }
function mp_value($value) { return $value === null || $value === '' ? 'Sem dados' : (string)$value; }
function mp_quantity($value,$unit) { return $value === null ? 'Sem dados' : number_format((float)$value,3,',',' ').' '.($unit ?: '(unidade não registada)'); }
function mp_fields(array $fields) {
    echo '<dl class="row mb-0">';
    foreach ($fields as $label=>$value) echo '<dt class="col-sm-4">'.h($label).'</dt><dd class="col-sm-8">'.h(mp_value($value)).'</dd>';
    echo '</dl>';
}
function mp_table($service,$id,$tab,$section,$title,array $columns,array $filters,$costs = false) {
    $data = $service->section($id,$section,$filters,$costs);
    echo '<section class="card mb-3"><div class="card-body"><h2 class="h5">'.h($title).'</h2><div class="table-responsive"><table class="table table-hover align-middle"><thead><tr>';
    foreach ($columns as $label) echo '<th>'.h($label).'</th>';
    echo '</tr></thead><tbody>';
    foreach ($data['rows'] as $row) {
        echo '<tr>';
        foreach ($columns as $key=>$label) {
            $value = mp_value($row[$key] ?? null); $url = '';
            if ($key === 'article' && !empty($row['article_id'])) $url = 'erp.php?page=article_profile&id='.(int)$row['article_id'];
            if ($key === 'order_number' && !empty($row['production_order_id'])) $url = 'production_dossier.php?id='.(int)$row['production_order_id'];
            if ($key === 'version_no' && !empty($row['version_id'])) $url = 'erp.php?page=article_profile&tab=routing&id='.(int)$row['article_id'].'&version_id='.(int)$row['version_id'];
            echo '<td>'.($url !== '' ? '<a href="'.h($url).'">'.h($value).'</a>' : h($value)).'</td>';
        }
        echo '</tr>';
    }
    if (!$data['rows']) echo '<tr><td colspan="'.count($columns).'" class="text-muted py-3">Sem registos com estes filtros.</td></tr>';
    echo '</tbody></table></div><nav class="d-flex flex-wrap align-items-center gap-2" aria-label="'.h('Paginação: '.$title).'"><span class="small text-muted">'.(int)$data['total'].' registos · Página '.(int)$data['page'].' / '.(int)$data['pages'].'</span>';
    foreach ([-1=>'Anterior',1=>'Seguinte'] as $step=>$label) {
        $page = $data['page']+$step;
        if ($page > 0 && $page <= $data['pages']) echo '<a class="btn btn-sm btn-outline-secondary" href="'.h(mp_url($id,$tab,array_merge($filters,['p'=>$page]))).'">'.$label.'</a>';
    }
    echo '</nav></div></section>';
}
$pageTitle = 'Ficha de material'; $navbarClockControl = [];
require __DIR__.'/partials/header.php';
$groups = ['raw_material'=>'Matéria-prima','subsidiary'=>'Subsidiário','consumable'=>'Consumível (legado)','finished_product'=>'Produto acabado','merchandise'=>'Mercadoria','packaging'=>'Embalagem','other'=>'Outro'];
$identity = ['Código interno'=>$material['code'],'Designação'=>$material['description'],'Grupo'=>$groups[$material['product_category']] ?? $material['product_category'],'Tipo'=>$material['type_name'],'Característica'=>$material['feature_name'],'Tipo de tinta'=>$material['ink_name'],'Unidade principal'=>$material['unit_code'],'Fornecedor principal'=>$material['supplier_name'],'Estado'=>$material['status']];
?>
<link rel="stylesheet" href="assets/customer-profile.css">
<main class="container-fluid py-4 customer-profile">
<header class="d-flex flex-wrap justify-content-between gap-3 mb-4"><div><a class="btn btn-sm btn-outline-secondary" href="erp.php?page=raw_materials"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Voltar à lista</a><div class="small text-muted mt-3"><?=h($material['code'])?></div><h1 class="h3"><?=h($material['description'])?></h1><div class="text-muted"><?=h($identity['Grupo'].' · '.mp_value($material['type_name']).' · '.mp_value($material['unit_code']).' · '.mp_value($material['supplier_name']))?></div><span class="badge text-bg-<?=$material['status']==='Ativo'?'success':'secondary'?>"><?=h($material['status'])?></span></div>
<?php if (gt_erp_user_can($pdo,$user,'erp.master_data')): ?><div><a class="btn btn-outline-primary" href="erp.php?page=raw_materials&amp;raw_material_id=<?=$id?>#raw-material-editor">Editar material</a></div><?php endif;?></header>
<div class="row g-3 mb-4">
<?php $cards = ['Stock físico'=>mp_quantity($summary['stock']['physical'],$material['unit_code']),'Quantidade reservada'=>mp_quantity($summary['stock']['reserved'],$material['unit_code']),'Stock disponível'=>mp_quantity($summary['stock']['available'],$material['unit_code']),'Última entrada'=>mp_value($summary['last_entry']),'Consumo no período'=>$summary['consumption']?implode(' · ',array_map(function($r){return mp_quantity($r['quantity'],$r['unit_code']);},$summary['consumption'])):'Sem consumos registados','Lotes identificados'=>(string)$summary['lots']]; foreach ($cards as $label=>$value): ?>
<div class="col-6 col-lg-4 col-xl-2"><div class="card h-100"><div class="card-body"><div class="small text-muted"><?=h($label)?></div><div class="fw-semibold mt-2"><?=h($value)?></div></div></div></div>
<?php endforeach;?></div>
<nav class="cp-tabs mb-4" aria-label="Separadores da ficha de material"><div class="d-flex flex-wrap gap-2"><?php $tabIcons=['overview'=>'bi-grid','technical'=>'bi-sliders','stock'=>'bi-box-seam','lots'=>'bi-upc-scan','consumptions'=>'bi-clipboard2-check','suppliers'=>'bi-graph-up','documents'=>'bi-file-earmark-text']; foreach ($tabs as $key=>$label): ?><a class="btn btn-sm <?=$tab===$key?'btn-primary':'btn-outline-secondary'?>" <?=$tab===$key?'aria-current="page"':''?> href="<?=h(mp_url($id,$key,array_merge($filters,['p'=>1])))?>"><i class="bi <?=h($tabIcons[$key])?> me-1" aria-hidden="true"></i><?=h($label)?></a><?php endforeach;?></div></nav>
<form method="get" class="row g-2 mb-4"><input type="hidden" name="page" value="material_profile"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="tab" value="<?=h($tab)?>"><div class="col-sm-4"><label class="form-label" for="mp-q">Pesquisar nas tabelas</label><input id="mp-q" class="form-control" name="q" value="<?=h($filters['q'])?>"></div><div class="col-sm-3"><label class="form-label" for="mp-from">Desde</label><input id="mp-from" class="form-control" type="date" name="from" value="<?=h($filters['from'])?>"></div><div class="col-sm-3"><label class="form-label" for="mp-to">Até</label><input id="mp-to" class="form-control" type="date" name="to" value="<?=h($filters['to'])?>"></div><div class="col-sm-2 align-self-end"><button class="btn btn-primary">Filtrar</button></div><div class="form-text">O período filtra movimentos, etiquetas e consumos. Os saldos representam o stock atual.</div></form>
<?php if ($tab==='overview'): ?>
<section class="card mb-3"><div class="card-body"><h2 class="h5">Identificação e classificação</h2><?php mp_fields($identity); ?></div></section>
<?php mp_table($service,$id,$tab,'suppliers','Referências dos fornecedores',['supplier_code'=>'Fornecedor','supplier_name'=>'Nome','supplier_reference'=>'Referência do fornecedor'],$filters); mp_table($service,$id,$tab,'movements','Movimentos recentes',['movement_date'=>'Data','movement_number'=>'Movimento','movement_type'=>'Tipo','quantity'=>'Quantidade','lot'=>'Lote','source_type'=>'Origem'],$filters); ?>
<p class="small text-muted">Disponível = físico − reservado − bloqueado. As quantidades dos saldos usam a unidade principal configurada. A unidade original dos movimentos não foi registada no esquema existente.</p>
<?php elseif ($tab==='technical'): ?>
<section class="card"><div class="card-body"><h2 class="h5">Dados técnicos registados</h2><?php
$technical = ['Cor'=>$material['color_name'],'Código da cor'=>$material['color_code'],'Tipo'=>$material['type_name'],'Características'=>$material['feature_name'],'Unidade de medida'=>$material['unit_code'],'Observações'=>$material['notes']];
foreach (['width'=>'Largura (unidade não registada)','grammage'=>'Gramagem (unidade não registada)','thickness'=>'Espessura (unidade não registada)','length'=>'Comprimento (unidade não registada)','weight_per_unit'=>'Peso por unidade (unidade não registada)'] as $key=>$label) if ($material[$key] !== null) $technical[$label]=$material[$key];
mp_fields($technical); ?><p class="text-muted small mt-3 mb-0">Composição e condições de armazenamento não têm campos próprios no esquema atual. Não são inferidas da designação.</p></div></section>
<?php elseif ($tab==='stock'): ?>
<p class="small text-muted">Saldos na unidade principal <?=h(mp_value($material['unit_code']))?>. A unidade original dos movimentos não foi registada; nenhuma conversão é aplicada.</p>
<?php mp_table($service,$id,$tab,'stock','Stock físico por localização',['warehouse'=>'Armazém','location'=>'Localização','lot'=>'Lote','physical_qty'=>'Físico','reserved_qty'=>'Reservado','blocked_qty'=>'Bloqueado','available'=>'Disponível'],$filters); mp_table($service,$id,$tab,'reservations','Reservas por OF / operação',['order_number'=>'OF','production_order_operation_id'=>'Operação da OF','required_qty'=>'Necessário','reserved_qty'=>'Reservado','lot'=>'Lote','warehouse'=>'Armazém','location'=>'Localização'],$filters); mp_table($service,$id,$tab,'movements','Entradas, saídas, ajustes e transferências',['movement_date'=>'Data','movement_number'=>'Movimento','movement_type'=>'Tipo','quantity'=>'Quantidade','lot'=>'Lote','source_type'=>'Origem','source_id'=>'ID origem','order_reference'=>'Referência'],$filters); ?>
<?php elseif ($tab==='lots'): ?>
<p class="small text-muted">Os saldos por lote não demonstram a quantidade inicial nem a data de receção. As datas das etiquetas são apresentadas como datas de etiqueta. Não se somam etiquetas aos saldos de stock.</p>
<?php mp_table($service,$id,$tab,'lots','Lotes nos saldos atuais',['lot'=>'Lote','physical'=>'Quantidade física','reserved'=>'Reservado'],$filters); $labelColumns=['barcode'=>'Identificador único','entry_number'=>'Entrada de origem','supplier_lot'=>'Lote do fornecedor','label_date'=>'Data da etiqueta','initial_weight_kg'=>'Peso inicial (kg)','weight_kg'=>'Peso remanescente (kg)','status'=>'Estado','warehouse'=>'Armazém','location'=>'Localização']; mp_table($service,$id,$tab,'rolls','Rolos identificados',array_merge($labelColumns,['initial_metres'=>'Comprimento inicial (m)','metres'=>'Comprimento remanescente (m)']),$filters); mp_table($service,$id,$tab,'inks','Unidades de tinta identificadas',$labelColumns,$filters); mp_table($service,$id,$tab,'roll_trace','Relações reais entre rolos e OF',['order_number'=>'OF','source_barcode'=>'Rolo de origem','resulting_barcode'=>'Rolo resultante','consumed_metres'=>'Consumo (m)','consumed_weight_kg'=>'Consumo (kg)','created_at'=>'Data'],$filters); ?>
<?php elseif ($tab==='consumptions'): ?>
<p class="small text-muted">BOM e routing são previsões distintas. Não são somadas. Consumos e movimentos ligados pelo mesmo registo não são contabilizados duas vezes.</p>
<?php mp_table($service,$id,$tab,'bom','Artigos / BOM',['article'=>'Artigo','description'=>'Designação','quantity_per_unit'=>'Qtd. por unidade','waste_percent'=>'Desperdício (%)','notes'=>'Nota'],$filters); mp_table($service,$id,$tab,'article_colors','Artigos com o código nas cores de impressão',['article'=>'Artigo','description'=>'Designação','technical_front'=>'Ficha: frente','technical_back'=>'Ficha: verso','order_front'=>'OF: frente','order_back'=>'OF: verso'],$filters); mp_table($service,$id,$tab,'routing','Roteiros e operações',['article'=>'Artigo','version_no'=>'Versão','status'=>'Estado da versão','operation'=>'Operação','operation_name'=>'Designação','quantity_per_unit'=>'Qtd. por unidade','reserve_on_order'=>'Reservar na OF'],$filters); mp_table($service,$id,$tab,'consumptions','Consumos registados / OF',['order_number'=>'OF','production_order_operation_id'=>'Operação da OF','created_at'=>'Data','planned_quantity'=>'Previsto registado','quantity'=>'Consumido registado','unit_code'=>'Unidade registada','lot'=>'Lote','completed_at'=>'Concluído em','source_movement_id'=>'Movimento associado'],$filters); ?>
<?php elseif ($tab==='suppliers'): ?>
<section class="card mb-3"><div class="card-body"><h2 class="h5">Fornecedor principal</h2><?php mp_fields(['Código do fornecedor'=>$material['supplier_code'],'Nome'=>$material['supplier_name']]); ?></div></section>
<?php mp_table($service,$id,$tab,'suppliers','Referências dos fornecedores',['supplier_code'=>'Fornecedor','supplier_name'=>'Nome','supplier_reference'=>'Referência do fornecedor'],$filters); if ($canCosts): ?>
<section class="card mb-3"><div class="card-body"><h2 class="h5">Custos configurados</h2><?php mp_fields(['Preço padrão registado'=>$material['standard_price'],'Último preço registado'=>$material['last_price'],'Preço médio registado (não recalculado)'=>$material['average_price'],'Unidade principal'=>$material['unit_code']]); ?><p class="small text-muted mt-3 mb-0">Os valores configurados podem incluir zeros por omissão. Não demonstram um custo médio calculável nem uma unidade histórica de valorização.</p></div></section>
<?php mp_table($service,$id,$tab,'prices','Custos registados nas entradas',['movement_date'=>'Data','movement_number'=>'Movimento','quantity'=>'Quantidade','unit_cost'=>'Custo unitário registado','total_cost'=>'Custo total registado','source_type'=>'Origem'],$filters,true); else: ?><p class="text-muted">Sem permissão para consultar custos.</p><?php endif; ?>
<?php elseif ($tab==='documents'): mp_table($service,$id,$tab,'documents','Fichas técnicas, segurança, certificados e anexos existentes',['title'=>'Título','document_type'=>'Tipo','version'=>'Versão','valid_until'=>'Validade','status'=>'Estado','notes'=>'Observações'],$filters); ?>
<p class="small text-muted">A consulta apresenta os metadados existentes. A disponibilização de ficheiros de materiais depende de um endpoint de download com as permissões adequadas.</p>
<?php endif; ?>
</main>
<?php require __DIR__.'/partials/footer.php'; ?>
