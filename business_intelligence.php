<?php
require_once __DIR__.'/helpers.php';
require_once __DIR__.'/erp_migrations.php';
require_once __DIR__.'/app/Services/BusinessIntelligence.php';
require_login();
erp_run_phase1_migrations($pdo);
$user=current_user($pdo)?:[];
if(!erp_user_can($pdo,$user,'erp.bi.view')){http_response_code(403);exit('Acesso reservado ao Business Intelligence.');}
$financial=erp_user_can($pdo,$user,'erp.bi.financial');
$bi=new BusinessIntelligence($pdo,$_GET,$financial);

if(($_GET['format']??'')==='json'){
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: private, no-store');
    echo json_encode($bi->payload(),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;
}
if(($_GET['format']??'')==='csv'){
    if(!erp_user_can($pdo,$user,'erp.reports_export')){http_response_code(403);exit('Sem permissão para exportar.');}
    $payload=$bi->payload();header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="bi_ordens_'.date('Ymd_His').'.csv"');
    $out=fopen('php://output','wb');fwrite($out,"\xEF\xBB\xBF");fputcsv($out,['OF','Cliente','Artigo','Estado','Planeado','Produzido','Prazo'],';');
    foreach($payload['details'] as $r)fputcsv($out,[$r['order_number'],$r['customer'],$r['article'],$r['status'],$r['planned_quantity'],$r['produced_quantity'],$r['due_date']],';');fclose($out);exit;
}
$options=$bi->options();$initial=$bi->payload();$pageTitle='Business Intelligence';$bodyClass='bg-light bi-page';require __DIR__.'/partials/header.php';
function bi_options(array $rows,int $selected,string $empty){echo '<option value="">'.h($empty).'</option>';foreach($rows as $r){$v=(int)$r['value'];echo '<option value="'.$v.'"'.($v===$selected?' selected':'').'>'.h($r['label']).'</option>';}}
?>
<link href="assets/business-intelligence.css?v=<?=h((string)(@filemtime(__DIR__.'/assets/business-intelligence.css')?:'1'))?>" rel="stylesheet">
<div class="bi-dashboard" id="biDashboard" data-refresh="<?= (int)($initial['settings']['bi_tv_refresh_seconds']??300) ?>" data-rotate="<?= (int)($initial['settings']['bi_tv_rotate_seconds']??30) ?>">
  <div class="bi-toolbar">
    <div><p class="bi-eyebrow"><i class="bi bi-broadcast-pin"></i> Painel executivo</p><h2>Visão integrada da operação</h2><p class="text-muted mb-0">Indicadores calculados exclusivamente sobre dados reais do ERP.</p></div>
    <div class="d-flex gap-2"><button class="btn btn-outline-primary" type="button" data-bi-refresh><i class="bi bi-arrow-clockwise"></i> Atualizar</button><button class="btn btn-primary" type="button" data-bi-tv><i class="bi bi-display"></i> Modo TV</button></div>
  </div>
  <form class="bi-filters" id="biFilters">
    <div class="bi-shortcuts" role="group" aria-label="Períodos rápidos"><button type="button" data-range="today">Hoje</button><button type="button" data-range="week">Esta semana</button><button type="button" data-range="month" class="active">Este mês</button><button type="button" data-range="quarter">Trimestre</button><button type="button" data-range="year">Este ano</button><button type="button" data-range="12months">12 meses</button></div>
    <div class="bi-filter-grid">
      <label>De<input class="form-control" type="date" name="from" value="<?=h($initial['filters']['from'])?>"></label><label>Até<input class="form-control" type="date" name="to" value="<?=h($initial['filters']['to'])?>"></label>
      <label>Cliente<select class="form-select" name="customer"><?php bi_options($options['customers'],(int)$initial['filters']['customer'],'Todos');?></select></label>
      <label>Fornecedor<select class="form-select" name="supplier" disabled title="Sem documentos de compra no modelo atual"><?php bi_options($options['suppliers'],(int)$initial['filters']['supplier'],'Indisponível — sem compras');?></select></label>
      <label>Artigo<select class="form-select" name="article"><?php bi_options($options['articles'],(int)$initial['filters']['article'],'Todos');?></select></label>
      <label>Ordem de fabrico<select class="form-select" name="order"><?php bi_options($options['orders'],(int)$initial['filters']['order'],'Todas');?></select></label>
      <label>Máquina<select class="form-select" name="machine"><?php bi_options($options['machines'],(int)$initial['filters']['machine'],'Todas');?></select></label>
      <label>Setor / operação<select class="form-select" name="operation"><?php bi_options($options['operations'],(int)$initial['filters']['operation'],'Todos');?></select></label>
      <label>Estado<select class="form-select" name="status"><option value="">Todos</option><?php foreach($options['statuses'] as $r):?><option value="<?=h($r['value'])?>" <?=($r['value']===$initial['filters']['status'])?'selected':''?>><?=h($r['label'])?></option><?php endforeach;?></select></label>
    </div>
  </form>
  <div class="bi-update-line"><span data-bi-status>Dados atualizados</span><strong data-bi-updated></strong><span class="bi-live"><i></i> atualização automática</span></div>
  <section class="bi-tv-slide bi-kpi-overview" data-bi-slide>
    <div class="bi-kpi-group"><div class="bi-section-title"><span>1</span><div><small>Cards principais</small><h3>Execução da produção</h3></div></div><div class="bi-kpis" data-bi-kpis-primary></div></div>
    <div class="bi-tv-summary-charts" aria-label="Resumo gráfico da produção e tempos">
      <article class="bi-panel"><header><div><small>Produção</small><h3>Planeada vs. realizada</h3></div></header><div class="bi-chart"><canvas id="chartTvPlan"></canvas></div></article>
      <article class="bi-panel"><header><div><small>Tempos</small><h3>Horas trabalhadas, produtivas e de máquina</h3></div></header><div class="bi-chart"><canvas id="chartTvHours"></canvas></div></article>
    </div>
    <div class="bi-kpi-group"><div class="bi-section-title"><span>2</span><div><small>Cards principais</small><h3>Desvios e controlo</h3></div></div><div class="bi-kpis" data-bi-kpis-control></div></div>
  </section>
  <section class="bi-tv-slide bi-chart-overview" data-bi-slide><div class="bi-grid bi-charts-grid"><article class="bi-panel"><header><div><small>Produção</small><h3>Planeada vs. realizada</h3></div></header><div class="bi-chart"><canvas id="chartPlan"></canvas></div></article><article class="bi-panel"><header><div><small>Tempos</small><h3>Horas trabalhadas, produtivas e de máquina</h3></div></header><div class="bi-chart"><canvas id="chartHours"></canvas></div></article><article class="bi-panel"><header><div><small>Máquinas</small><h3>Utilização por máquina</h3></div></header><div class="bi-chart"><canvas id="chartMachines"></canvas></div></article><article class="bi-panel"><header><div><small>Desperdício</small><h3>Por máquina/operação</h3></div></header><div class="bi-chart"><canvas id="chartWaste"></canvas></div></article><article class="bi-panel"><header><div><small>Paragens</small><h3>Principais causas</h3></div></header><div class="bi-chart"><canvas id="chartStops"></canvas></div></article><article class="bi-panel"><header><div><small>Fluxo operacional</small><h3>OF por estado</h3></div></header><div class="bi-chart"><canvas id="chartStatus"></canvas></div></article></div></section>
  <section class="bi-bottom bi-tv-slide" data-bi-slide><article class="bi-panel bi-alerts"><header><div><small>Prioridade executiva</small><h3>Pontos de atenção</h3></div><span>máximo 5</span></header><div data-bi-alerts></div></article><article class="bi-panel bi-unavailable"><header><div><small>Qualidade dos dados</small><h3>Indicadores indisponíveis</h3></div></header><ul data-bi-unavailable></ul></article></section>
  <section class="bi-panel bi-table"><header><div><small>Rastreabilidade</small><h3>Registos que sustentam os indicadores</h3></div><div class="d-flex gap-2"><input class="form-control form-control-sm" type="search" placeholder="Pesquisar" data-bi-search><button class="btn btn-sm btn-outline-success" type="button" data-bi-export><i class="bi bi-file-earmark-spreadsheet"></i> CSV</button></div></header><div class="table-responsive"><table class="table table-hover"><thead><tr><th data-sort="order_number">OF</th><th data-sort="customer">Cliente</th><th>Artigo</th><th>Estado</th><th>Planeado</th><th>Produzido</th><th>Prazo</th></tr></thead><tbody data-bi-table></tbody></table></div><footer data-bi-pagination></footer></section>
  <div class="bi-tv-help"><span data-bi-tv-progress>Vista 1 de 3</span><i class="bi bi-dot"></i><span>ESC ou botão para sair</span><button type="button" data-bi-tv-exit><i class="bi bi-fullscreen-exit"></i> Sair</button></div>
</div>
<div class="modal fade" id="biDetailModal" tabindex="-1" aria-labelledby="biDetailTitle" aria-hidden="true"><div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><div><small class="text-primary fw-bold text-uppercase">Detalhe do indicador</small><h2 class="modal-title fs-5" id="biDetailTitle"></h2></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div><div class="modal-body"><div class="bi-formula" data-bi-formula></div><div class="table-responsive mt-3"><table class="table table-hover"><thead><tr><th>OF</th><th>Cliente</th><th>Artigo</th><th>Estado</th><th>Planeado</th><th>Produzido</th><th>Prazo</th></tr></thead><tbody data-bi-detail-table></tbody></table></div></div></div></div></div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>window.GT_BI_INITIAL=<?=json_encode($initial,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;</script>
<script src="assets/business-intelligence.js?v=<?=h((string)(@filemtime(__DIR__.'/assets/business-intelligence.js')?:'1'))?>"></script>
<?php require __DIR__.'/partials/footer.php'; ?>
