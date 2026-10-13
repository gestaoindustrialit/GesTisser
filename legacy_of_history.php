<?php
require_once __DIR__.'/historical-import/legacy-of-bootstrap.php';
if(!gt_erp_user_can($pdo,$user,'erp.view')||!gt_erp_user_can($pdo,$user,'erp.customers')){http_response_code(403);exit('Sem permissão para consultar OFs.');}
$financial=gt_erp_user_can($pdo,$user,'erp.costs_view');$admin=(int)$user['is_admin']===1;
$service=new LegacyOfHistory($pdo);$id=(int)lo_text($_GET,'id','0');$error='';$message='';
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
    if(!$admin){http_response_code(403);exit('Apenas um administrador pode resolver referências.');}
    try {
        lo_csrf();if(lo_text($_POST,'action')!=='resolve')throw new RuntimeException('Ação não permitida.');
        (new LegacyOfHistory(lo_write_connection()))->resolve($id,lo_text($_POST,'kind'),lo_text($_POST,'target'),lo_text($_POST,'evidence'),app_config('env'),(int)$user['id'],lo_backup_dir());$message='Correspondência resolvida e auditada; valores originais preservados.';
    }catch(Throwable $e){if(http_response_code()<400)http_response_code(422);$error=$e->getMessage();}
}
$detail=$id>0?$service->detail($id):null;
if($id>0&&!$detail){http_response_code(404);exit('OF histórica não encontrada.');}
// Customer-scoped navigation requires the same permission as the customer profile.
if(((int)lo_text($_GET,'customer','0')>0||$detail)&&!gt_erp_user_can($pdo,$user,'erp.customers')){http_response_code(403);exit('Sem permissão para consultar histórico de clientes.');}
$pageTitle=$detail?'OF histórica · '.$detail['legacy_of_number']:'Consulta unificada de OFs';require __DIR__.'/partials/header.php';
?>
<div class="container py-4"><p><a href="legacy_of_history.php">Pesquisa de OFs</a><?php if($admin):?> · <a href="legacy_of_import.php">Importação e auditoria</a> · <a href="legacy_of_history.php?origin=Bobinas&amp;pending=1">Referências pendentes</a><?php endif;?></p>
<?php if($error):?><div class="alert alert-danger"><?= h($error) ?></div><?php endif;?><?php if($message):?><div class="alert alert-success"><?= h($message) ?></div><?php endif;?>
<?php if(!$detail):$legacyStandalone=true;require __DIR__.'/partials/legacy-of-list.php';else:?>
<h1 class="h3">OF <?= h($detail['legacy_of_number']) ?> <span class="badge bg-secondary">Bobinas · histórico</span></h1><p>Consulta histórica. Identificador original: <?= h($detail['legacy_id']) ?> · Lote <?= (int)$detail['import_batch_id'] ?> · Importação <?= h($detail['imported_at']) ?>.</p>
<h2 class="h5 mt-4">Dados gerais</h2><dl class="row">
<?php foreach(['legacy_order_id'=>'ID da encomenda original','legacy_order_number'=>'Encomenda original','legacy_customer_code'=>'Código original do cliente','customer_name'=>'Cliente associado','legacy_product_code'=>'Código original do artigo','product_name'=>'Artigo associado','planned_quantity'=>'Quantidade planeada','produced_quantity'=>'Quantidade produzida','quantity_unit'=>'Unidade registada','created_at_legacy'=>'Criação','started_at_legacy'=>'Início','finished_at_legacy'=>'Fim','legacy_status'=>'Estado original','observations'=>'Observações'] as $key=>$label):?><dt class="col-sm-4"><?= h($label) ?></dt><dd class="col-sm-8"><?= h(lo_value($detail[$key])) ?></dd><?php endforeach;?></dl>
<h2 class="h5 mt-4">Produção histórica</h2><div class="table-responsive"><table class="table table-sm"><thead><tr><th>Operação original</th><th>Tempo previsto</th><th>Tempo registado</th><th>Quantidade</th><?php if($financial):?><th>Custo registado</th><?php endif;?><th>Origem</th></tr></thead><tbody>
<?php foreach($detail['operations'] as $op):?><tr><td><?= h($op['operation_code'].' · '.$op['operation_name']) ?></td><td><?= h(lo_value($op['planned_time'])) ?> h</td><td><?= h(lo_value($op['registered_time'])) ?> <?= h($op['time_unit']) ?></td><td><?= h(lo_value($op['processed_quantity']).' '.($op['quantity_unit']?:'(unidade não registada)')) ?></td><?php if($financial):?><td><?= h(lo_value($op['registered_cost']).' '.($op['currency']?:'').' '.($op['cost_type']?:'')) ?></td><?php endif;?><td><?= h($op['origin']) ?></td></tr><?php endforeach;?>
<?php if(!$detail['operations']):?><tr><td colspan="6">Sem operações históricas fornecidas.</td></tr><?php endif;?></tbody></table></div>
<?php if($detail['operations_truncated']):?><p>Consulta limitada a 1.000 operações; o lote auditado conserva o relatório completo.</p><?php endif;?>
<?php $s=$detail['summary']?:[];?><h2 class="h5 mt-4">Indicadores registados</h2><p>Tempo total: <?= h(lo_value($s['total_production_time']??null)) ?> h · Preparação: <?= h(lo_value($s['setup_time']??null)) ?> h · Execução: <?= h(lo_value($s['execution_time']??null)) ?> h.</p>
<?php if($financial):?><h2 class="h5 mt-4">Custeio histórico</h2><dl class="row"><?php foreach(['labor_cost'=>'Mão de obra','material_cost'=>'Materiais','other_cost'=>'Outros custos','total_cost'=>'Total registado','unit_cost'=>'Custo unitário','currency'=>'Moeda','cost_type'=>'Real / estimado','origin'=>'Origem','calculation_method'=>'Método'] as $key=>$label):?><dt class="col-sm-4"><?= h($label) ?></dt><dd class="col-sm-8"><?= h(lo_value($s[$key]??null)) ?></dd><?php endforeach;?></dl><?php endif;?>
<h2 class="h5 mt-4">Rastreabilidade</h2><p>Sem associações comprovadas a lotes de ráfia, tintas ou movimentos. Área preparada para futura integração documental.</p>
<?php if($admin):?><h2 class="h5 mt-4">Correspondências administrativas</h2><p>Cliente: <?= h($detail['customer_resolution']) ?> · Artigo: <?= h($detail['product_resolution']) ?>. A resolução preserva os códigos originais e exige evidência documental.</p><form method="post"><input type="hidden" name="_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="resolve"><label>Referência <select name="kind"><option value="customer">Cliente</option><option value="product">Artigo</option></select></label> <label>ID atual <input name="target" inputmode="numeric" required></label> <label>Evidência <input name="evidence" maxlength="2000" required></label> <button class="btn btn-outline-primary">Resolver com backup e auditoria</button></form>
<details class="mt-3"><summary>Auditoria desta OF</summary><?php $q=$pdo->prepare('SELECT user_id,action,before_json,after_json,evidence,created_at FROM erp_legacy_of_audit WHERE legacy_of_id=? ORDER BY id DESC LIMIT 100');$q->execute([$id]);foreach($q->fetchAll() as $a):?><pre><?= h(json_encode($a,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)) ?></pre><?php endforeach;?></details><?php endif;?>
<?php endif;?></div><?php require __DIR__.'/partials/footer.php'; ?>
