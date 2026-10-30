<?php
require_once __DIR__.'/helpers.php';
require_once __DIR__.'/app/Services/ColorimetryService.php';
require_once __DIR__.'/app/Services/CentralPrinting.php';
require_login();gt_erp_run_phase1_migrations($pdo);ColorimetryService::migrate($pdo);
$inkUser=current_user($pdo);$ink=new ColorimetryService($pdo,$inkUser);
try{$ink->authorize();}catch(Throwable $e){http_response_code(403);exit('Sem permissão para Tintas / Cores.');}
header('Cache-Control: no-store');$error='';$success='';$tab=$_GET['tab']??'search';$containerId=(int)($_GET['container_id']??0);$formulaId=(int)($_GET['formula_id']??0);$productionOrderId=(int)($_GET['production_order_id']??0);
if($tab==='library'&&!$ink->can('ink.formula.create')&&!$ink->can('ink.formula.approve')){http_response_code(403);exit('Sem permissão de gestão da biblioteca.');}
if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        if(!validate_csrf_or_abort(false))throw new RuntimeException('Pedido inválido. Atualize a página.');
        $data=(array)($_POST['data']??[]);
        if(($_POST['action']??'')==='attach'){
            $ink->authorize('ink.formula.create');$file=$_FILES['reference']??[];
            if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||(int)($file['size']??0)>5242880||!is_uploaded_file($file['tmp_name']??''))throw new RuntimeException('Anexo inválido (máximo 5 MB).');
            $data['content']=base64_encode(file_get_contents($file['tmp_name']));$data['name']=$file['name'];
        }
        $result=$ink->execute((string)($_POST['action']??''),$data,(string)($_POST['request_key']??''));
        $url='colorimetry.php?tab='.urlencode($tab).'&saved=1';
        if(!empty($result['containers']))$url='colorimetry.php?container_id='.(int)$result['containers'][0].'&saved=1';
        elseif(!empty($result['container_id']))$url='colorimetry.php?container_id='.(int)$result['container_id'].'&saved=1';
        elseif(!empty($result['formula_id']))$url='colorimetry.php?tab=library&formula_id='.(int)$result['formula_id'].'&saved=1';
        if($productionOrderId)$url.='&production_order_id='.$productionOrderId;
        redirect($url);
    }catch(Throwable $e){$error=$e->getMessage();if((int)$e->getCode()===403)http_response_code(403);}
}
function ink_form($action)
{
    echo '<form method="post" enctype="multipart/form-data" class="card card-body mb-3">'.csrf_input().'<input type="hidden" name="request_key" value="'.h((string)($_POST['request_key']??bin2hex(random_bytes(16)))).'"><input type="hidden" name="action" value="'.h($action).'">';
}
function ink_input($name,$label,$value='',$type='text',$required=false)
{
    echo '<label class="form-label d-block mb-3">'.h($label).'<input class="form-control form-control-lg" name="data['.h($name).']" type="'.h($type).'" value="'.h((string)$value).'" '.($required?'required':'').($type==='number'?' step="0.001" min="0" inputmode="decimal"':'').'></label>';
}
function ink_unit($name='unit'){ink_select($name,'Unidade',[['id'=>'g','label'=>'g'],['id'=>'kg','label'=>'kg']],'g',false);}
function ink_hidden($name,$value){echo '<input type="hidden" name="data['.h($name).']" value="'.h((string)$value).'">';}
function ink_select($name,$label,array $rows,$value='',$blank=true)
{
    echo '<label class="form-label d-block mb-3">'.h($label).'<select class="form-select form-select-lg" name="data['.h($name).']">';if($blank)echo '<option value="">—</option>';
    foreach($rows as $r)echo '<option value="'.h((string)$r['id']).'" '.((string)$r['id']===(string)$value?'selected':'').'>'.h($r['label']).'</option>';echo '</select></label>';
}
function ink_grams($mg){return number_format($mg/1000,3,',',' ');}
function ink_button($text){echo '<button class="btn btn-primary btn-lg">'.h($text).'</button></form>';}
$materials=$ink->query('SELECT r.id,r.code||" — "||r.description label FROM erp_raw_materials r JOIN erp_units u ON u.id=r.primary_unit_id WHERE r.status="Ativo" AND lower(u.code) IN ("g","kg") ORDER BY r.code');
$warehouses=$ink->query('SELECT id,code||" — "||name label FROM erp_warehouses ORDER BY code');
$types=$ink->query('SELECT id,name label FROM erp_ink_types WHERE is_active=1 ORDER BY name');
$colors=$ink->query('SELECT id,code||" — "||name label FROM erp_colors ORDER BY code');
function ink_components(array $materials,array $components=[],$actual=false,$stock=false)
{
    global $ink;
    echo '<div data-components><h3 class="h5">'.($actual?'Pesagens reais (g) — confirme cada valor':'Componentes (g)').'</h3>';
    $rows=$components?:[[],[],[]];
    foreach($rows as $i=>$c){echo '<div class="row g-2 mb-2" data-component-row><div class="col-8">';ink_select('components]['.$i.'][material_id','Matéria-prima',$materials,$c['material_id']??'');echo '</div><div class="col-4">';ink_input('components]['.$i.'][quantity','Massa',$actual?'':(isset($c['mg'])?ColorimetryService::grams($c['mg']):''),'number',$actual);echo '</div>';ink_unit('components]['.$i.'][unit');if($actual||$stock){echo '<div class="col-12">';ink_select('components]['.$i.'][source_balance_id','Stock de origem (matéria-prima / lote / saldo)',$ink->query('SELECT b.rowid id,r.code||" — "||COALESCE(b.lot,"sem lote")||" · "||(b.physical_qty-b.reserved_qty-b.blocked_qty)||" "||u.code label FROM erp_stock_balances b JOIN erp_raw_materials r ON r.id=b.item_id JOIN erp_units u ON u.id=r.primary_unit_id WHERE b.item_type="raw_material" AND b.physical_qty-b.reserved_qty-b.blocked_qty>0 ORDER BY r.code,b.lot'));ink_select('components]['.$i.'][source_label_id','Lata de origem (quando identificada por etiqueta)',$ink->query('SELECT l.id,r.code||" · "||l.barcode||" · "||l.weight_kg||" kg" label FROM erp_raw_material_ink_labels l JOIN erp_raw_materials r ON r.id=l.raw_material_id WHERE l.weight_kg>0 AND l.status NOT IN ("IN_USE","BLOCKED","CONSUMED") ORDER BY r.code,l.barcode'));echo '</div>';}echo '</div>';}
    echo '<button type="button" class="btn btn-outline-secondary btn-lg mb-3" data-add-component>Adicionar componente</button></div>';
}
$pageTitle='Tintas / Cores';require __DIR__.'/partials/header.php';
?>
<main class="container py-4" id="ink-module"><div class="d-flex gap-3 align-items-center mb-3"><a class="btn btn-outline-secondary btn-lg" href="shopfloor.php">← Shopfloor</a><h1 class="h3 mb-0">🎨 Tintas / Cores</h1></div>
<style>#ink-module .btn{min-height:44px}#ink-module input,#ink-module select{min-height:48px}#ink-module .swatch{display:inline-block;width:24px;height:24px;border:1px solid #555;vertical-align:middle}#ink-module pre{white-space:pre-wrap;word-break:break-word}#ink-module .card{overflow:hidden}</style>
<?php if($error):?><div class="alert alert-danger" role="alert"><?=h($error)?></div><?php endif;?><?php if(isset($_GET['saved'])):?><div class="alert alert-success">Operação registada.</div><?php endif;?>
<nav class="d-flex flex-wrap gap-2 mb-4" aria-label="Tintas e cores"><?php foreach(['search'=>'Pesquisar Cor','prepare'=>'Preparar Tinta','stock'=>'Stock de Tintas','history'=>'Histórico'] as $key=>$label):?><a class="btn btn-<?=$tab===$key?'primary':'outline-primary'?> btn-lg" href="?tab=<?=$key?>"><?=h($label)?></a><?php endforeach;?><?php if($ink->can('ink.formula.create')||$ink->can('ink.formula.approve')):?><a class="btn btn-outline-dark btn-lg" href="?tab=library">Biblioteca / Gestão</a><?php endif;?></nav>
<?php
if($containerId){
    try{$b=$ink->container($containerId);}catch(Throwable $e){echo '<div class="alert alert-danger">'.h($e->getMessage()).'</div>';$b=null;}
    if($b){?>
    <div class="card card-body mb-3"><h2 class="h4"><?=h($b['code'].' · '.$b['color_code'].' — '.$b['color_name'])?></h2><p><?=h($b['mix_code'].' · Fórmula #'.$b['formula_id'].' V'.$b['version'].' · Lote '.$b['lot'])?></p><p><strong><?=h(ink_grams($b['available_mg']))?> g disponíveis</strong> · <?=h($b['status'].' · '.$b['location'])?></p><p>Preparada por <?=h($b['operator'])?> · <?=h($b['prepared_at'])?> · Inicial: <?=h(ink_grams($b['initial_mg']))?> g</p><a class="btn btn-outline-primary btn-lg" href="prepared_ink_label.php?container_id=<?=$containerId?>">Imprimir / reimprimir etiqueta</a><h3 class="h5 mt-3">Composição física atual</h3><?php foreach(json_decode($b['composition_json'],true) as $c):?><p><?=h($c['code'].' — '.$c['name'].': '.ink_grams($c['mg']))?> g</p><?php endforeach;?></div>
    <?php ink_form('result');ink_hidden('container_id',$containerId);ink_select('result','Resultado',[['id'=>'Aprovado','label'=>'Aprovado'],['id'=>'Rejeitado','label'=>'Rejeitado']],'',false);ink_input('sample','Referência de amostra');ink_input('measurements','Valores medidos de cor (L*, a*, b*, ΔE, instrumento)');ink_input('notes','Observações');ink_button('Registar avaliação');
    ink_form('correct');ink_hidden('container_id',$containerId);ink_components($materials,[],false,true);ink_input('notes','Motivo / observações da afinação','','text',true);ink_input('sample','Referência de amostra');ink_input('measurements','Valores medidos');ink_button('Corrigir apenas o restante neste recipiente');
    foreach(['consume'=>'Registar consumo','return'=>'Devolver tinta','loss'=>'Registar perda'] as $action=>$title){ink_form($action);ink_hidden('container_id',$containerId);echo '<h3 class="h5">'.h($title).'</h3>';ink_input('quantity','Quantidade','','number',true);ink_unit();ink_input('production_order_id','ID da Ordem de Fabrico (opcional)',$productionOrderId?:'','number');if($action==='return')ink_select('consumption_event_id','Consumo de origem (obrigatório)',$ink->query('SELECT id,created_at||" · OF "||COALESCE(production_order_id,"—")||" · "||(-delta_mg/1000.0)||" g" label FROM erp_ink_events WHERE container_id=? AND action="consume" ORDER BY id DESC',[$containerId]));ink_input('notes','Motivo / referência','','text',true);ink_button($title);}
    if($ink->can('ink.formula.create')){ink_form('propose');ink_hidden('container_id',$containerId);ink_hidden('ink_type_id','');ink_input('notes','Instruções da fórmula proposta');ink_button('Propor nova fórmula a partir desta mistura');}
    if(!empty($inkUser['is_admin'])){ink_form('container_state');ink_hidden('container_id',$containerId);ink_select('status','Estado',array_map(function($v){return ['id'=>$v,'label'=>$v];},['Disponível','Reservado','Em utilização','Bloqueado']),$b['status'],false);ink_input('notes','Justificação','','text',true);ink_button('Alterar estado');ink_form('adjust');ink_hidden('container_id',$containerId);ink_select('direction','Sentido',[['id'=>'in','label'=>'Entrada'],['id'=>'out','label'=>'Saída']],'out',false);ink_input('quantity','Acerto','','number',true);ink_unit();ink_input('notes','Justificação','','text',true);ink_button('Registar acerto');}
    echo '<a class="btn btn-outline-secondary btn-lg mb-3" href="?tab=history&container_id_filter='.$containerId.'">Ver histórico deste recipiente</a>';
    }
}elseif($tab==='prepare'){
    $approved=$ink->query('SELECT f.id,c.code||" — "||c.name||" · "||f.family||" V"||f.version label FROM erp_ink_formulas f JOIN erp_colors c ON c.id=f.color_id WHERE f.status="Aprovada" ORDER BY c.code,f.version DESC');
    ?><form method="get" class="card card-body mb-3"><input type="hidden" name="tab" value="prepare"><label class="form-label">Fórmula aprovada<select class="form-select form-select-lg" name="formula_id" required><option value="">Selecione</option><?php foreach($approved as $f):?><option value="<?=(int)$f['id']?>" <?=$formulaId===(int)$f['id']?'selected':''?>><?=h($f['label'])?></option><?php endforeach;?></select></label><button class="btn btn-primary btn-lg">Selecionar fórmula</button></form><?php
    if($formulaId){$f=$ink->formula($formulaId);if($f['status']!=='Aprovada')echo '<div class="alert alert-warning">Amostra de desenvolvimento — fórmula ainda não aprovada.</div>';if($f['status']==='Aprovada'||($f['status']==='Em desenvolvimento'&&$ink->can('ink.formula.create'))){
        $components=json_decode($f['components_json'],true);$base=array_sum(array_column($components,'mg'));
        ink_form('prepare');ink_hidden('formula_id',$formulaId);echo '<h2 class="h4">'.h($f['code'].' — '.$f['name']).'</h2><p>'.h($f['notes']).'</p>';ink_input('quantity','Quantidade prevista a preparar','','number',true);ink_unit();
        echo '<div class="table-responsive"><table class="table"><thead><tr><th>Componente</th><th>Base (g)</th><th>%</th><th>A pesar (g)</th></tr></thead><tbody>';
        foreach($components as $c){echo '<tr><td>'.h($c['code'].' — '.$c['name']).'</td><td>'.h(ink_grams($c['mg'])).'</td><td>'.h(number_format($c['mg']/$base*100,4,',','')).'</td><td data-calculate-mg="'.(int)$c['mg'].'" data-base-mg="'.$base.'">—</td></tr>';}
        echo '</tbody></table></div>';ink_components($materials,$components,true);
        echo '<h3 class="h5">Distribuição pelos recipientes</h3><p>Os recipientes ficam bloqueados até à avaliação. A soma deve ser igual às pesagens reais.</p><div data-containers>';
        for($i=0;$i<1;$i++){echo '<div class="border rounded p-3 mb-3" data-container-row>';ink_input('containers]['.$i.'][quantity','Quantidade real neste recipiente','','number',true);ink_unit('containers]['.$i.'][unit');ink_input('containers]['.$i.'][lot','Lote','','text',true);ink_input('containers]['.$i.'][location','Localização física');ink_select('containers]['.$i.'][warehouse_id','Armazém',$warehouses);echo '</div>';}
        echo '<button type="button" class="btn btn-outline-secondary btn-lg mb-3" data-add-container>Adicionar recipiente</button></div>';ink_input('notes','Desvios / observações');ink_button('Confirmar pesagens e criar recipientes');
    }}
}elseif($tab==='library'){
    if(!$ink->can('ink.formula.create')&&!$ink->can('ink.formula.approve')){http_response_code(403);echo '<div class="alert alert-danger">Sem permissão de gestão da biblioteca.</div>';require __DIR__.'/partials/footer.php';exit;}
    if($ink->can('ink.formula.create')){
        $editColor=[];if(!empty($_GET['color_id'])){$r=$ink->query('SELECT c.*,d.customer_id,d.article_id,d.ink_type_id,d.substrate,d.finish,d.hex,d.notes,d.status FROM erp_colors c LEFT JOIN erp_ink_color_details d ON d.color_id=c.id WHERE c.id=?',[(int)$_GET['color_id']]);$editColor=$r?$r[0]:[];}
        echo '<details class="mb-3" '.($editColor?'open':'').'><summary class="btn btn-outline-primary btn-lg">Criar / editar cor</summary>';ink_form('color');ink_hidden('id',$editColor['id']??'');
        foreach(['code'=>'Código TISSER','name'=>'Designação','pantone'=>'Pantone','substrate'=>'Substrato','finish'=>'Acabamento','hex'=>'HEX aproximado (#RRGGBB)','notes'=>'Observações técnicas'] as $field=>$label)ink_input($field,$label,$editColor[$field]??'','text',in_array($field,['code','name'],true));
        ink_select('customer_id','Cliente (opcional)',$ink->query('SELECT id,code||" — "||name label FROM erp_customers ORDER BY name'),$editColor['customer_id']??'');ink_select('article_id','Artigo (opcional)',$ink->query('SELECT id,code||" — "||description label FROM erp_finished_products ORDER BY code'),$editColor['article_id']??'');ink_select('ink_type_id','Tipo de tinta',$types,$editColor['ink_type_id']??'');ink_select('status','Estado',array_map(function($v){return ['id'=>$v,'label'=>$v];},['Em desenvolvimento','Aprovada','Inativa']),$editColor['status']??'Em desenvolvimento',false);ink_button('Guardar cor');echo '</details>';
        $f=$formulaId?$ink->formula($formulaId):[];$copy=isset($_GET['copy']);$edit=$f&&!$copy&&$f['status']==='Em desenvolvimento'&&$f['approved_at']===null;
        echo '<details class="mb-3" '.($f?'open':'').'><summary class="btn btn-outline-primary btn-lg">'.($edit?'Editar rascunho':($f?'Criar nova versão':'Criar fórmula')).'</summary>';ink_form('formula');if($edit)ink_hidden('id',$f['id']);elseif($f&&!isset($_GET['duplicate']))ink_hidden('parent_id',$f['id']);
        ink_select('color_id','Cor',$colors,$f['color_id']??'');ink_select('ink_type_id','Tipo de tinta',$types,$f['ink_type_id']??'');ink_input('substrate','Substrato',$f['substrate']??'');ink_input('notes','Instruções técnicas',$f['notes']??'');ink_components($materials,$f?json_decode($f['components_json'],true):[]);ink_button($edit?'Guardar rascunho':'Criar versão em desenvolvimento');echo '</details>';
    }
    $rows=$ink->query('SELECT f.*,c.code,c.name,u.name creator,a.name approver FROM erp_ink_formulas f JOIN erp_colors c ON c.id=f.color_id JOIN users u ON u.id=f.created_by LEFT JOIN users a ON a.id=f.approved_by ORDER BY f.id DESC LIMIT 200');
    foreach($rows as $f){echo '<article class="card card-body mb-3"><h2 class="h5">'.h($f['code'].' — '.$f['name'].' · '.$f['family'].' V'.$f['version']).'</h2><p>'.h($f['status'].' · Criada por '.$f['creator'].' · '.$f['created_at'].' · Aprovação: '.$f['approver'].' '.$f['approved_at']).'</p><p>'.h($f['notes']).'</p>';
        foreach(json_decode($f['components_json'],true) as $c)echo '<p>'.h($c['code'].' — '.$c['name'].' · '.ink_grams($c['mg'])).' g</p>';
        $attachments=$ink->query('SELECT id,name FROM erp_ink_formula_attachments WHERE formula_id=?',[$f['id']]);foreach($attachments as $a)echo '<p><a href="ink_formula_attachment.php?id='.$a['id'].'">'.h($a['name']).'</a></p>';
        if($ink->can('ink.formula.create')&&$f['status']==='Em desenvolvimento'&&$f['approved_at']===null){ink_form('attach');ink_hidden('formula_id',$f['id']);echo '<label class="form-label">Imagem ou PDF de referência (até 5 MB)<input class="form-control" type="file" name="reference" accept="application/pdf,image/png,image/jpeg" required></label>';ink_button('Associar referência ao rascunho');}
        if($ink->can('ink.formula.create'))echo '<div class="d-flex gap-2 flex-wrap"><a class="btn btn-outline-primary" href="?tab=library&formula_id='.$f['id'].'">'.($f['status']==='Em desenvolvimento'?'Editar':'Nova versão').'</a><a class="btn btn-outline-secondary" href="?tab=library&formula_id='.$f['id'].'&copy=1&duplicate=1">Duplicar fórmula</a></div>';
        if($ink->can('ink.formula.create')&&$f['status']==='Em desenvolvimento')echo '<a class="btn btn-outline-primary" href="?tab=prepare&formula_id='.$f['id'].'">Preparar amostra</a>';
        if($ink->can('ink.formula.approve')){foreach(($f['status']==='Em desenvolvimento'?['Aprovada','Inativa']:($f['status']==='Aprovada'?['Inativa']:[])) as $state){ink_form('formula_state');ink_hidden('id',$f['id']);ink_hidden('status',$state);ink_button($state==='Aprovada'?'Aprovar fórmula':'Desativar fórmula');}}
        echo '</article>';
    }
}elseif($tab==='stock'){
    $q=trim($_GET['q']??'');?><form method="get" class="mb-3"><input type="hidden" name="tab" value="stock"><input type="hidden" name="production_order_id" value="<?=$productionOrderId?>"><label class="form-label w-100">Ler código de barras / pesquisar cor, recipiente ou lote<input autofocus class="form-control form-control-lg" name="q" value="<?=h($q)?>"></label><button class="btn btn-primary btn-lg">Pesquisar</button></form><?php
    $rows=$ink->query('SELECT b.*,c.code color_code,c.name color_name,m.code mix_code FROM erp_ink_containers b JOIN erp_ink_mixes m ON m.id=b.mix_id JOIN erp_ink_formulas f ON f.id=m.formula_id JOIN erp_colors c ON c.id=f.color_id WHERE b.code||" "||b.lot||" "||m.code||" "||c.code||" "||c.name LIKE ? ORDER BY b.id DESC LIMIT 200',['%'.$q.'%']);
    $totals=$ink->query('SELECT c.code,c.name,SUM(b.available_mg) total,SUM(CASE WHEN b.status="Bloqueado" THEN b.available_mg ELSE 0 END) blocked FROM erp_ink_containers b JOIN erp_ink_mixes m ON m.id=b.mix_id JOIN erp_ink_formulas f ON f.id=m.formula_id JOIN erp_colors c ON c.id=f.color_id GROUP BY c.id ORDER BY c.code');
    foreach($totals as $r)echo '<p><strong>'.h($r['code'].' — '.$r['name']).'</strong>: '.h(ink_grams($r['total'])).' g físicos · bloqueados: '.h(ink_grams($r['blocked'])).' g</p>';
    foreach($rows as $b)echo '<a class="card card-body mb-3 text-decoration-none" href="?container_id='.$b['id'].'&amp;production_order_id='.$productionOrderId.'"><strong>'.h($b['code'].' · '.$b['color_code'].' — '.$b['color_name']).'</strong><span>'.h(ink_grams($b['available_mg']).' g · '.$b['status'].' · Lote '.$b['lot'].' · '.$b['location']).'</span></a>';
}elseif($tab==='history'){
    ?><form method="get" class="card card-body mb-3"><input type="hidden" name="tab" value="history"><label class="form-label">Cor, fórmula, lote, cliente, artigo, recipiente ou colaborador<input class="form-control form-control-lg" name="q" value="<?=h($_GET['q']??'')?>"></label><label class="form-label">ID da OF<input class="form-control" type="number" name="order_id" value="<?=h($_GET['order_id']??'')?>"></label><label class="form-label">Desde<input class="form-control" type="date" name="from" value="<?=h($_GET['from']??'')?>"></label><label class="form-label">Até<input class="form-control" type="date" name="to" value="<?=h($_GET['to']??'')?>"></label><button class="btn btn-primary btn-lg">Filtrar histórico</button></form><?php
    $sql='SELECT e.*,u.name operator,b.code container_code,b.lot,m.code mix_code FROM erp_ink_events e JOIN users u ON u.id=e.user_id LEFT JOIN erp_ink_containers b ON b.id=e.container_id LEFT JOIN erp_ink_mixes m ON m.id=COALESCE(b.mix_id,CASE WHEN e.entity="mix" THEN e.entity_id END) LEFT JOIN erp_ink_formulas f ON f.id=COALESCE(m.formula_id,CASE WHEN e.entity="formula" THEN e.entity_id END) LEFT JOIN erp_colors c ON c.id=COALESCE(f.color_id,CASE WHEN e.entity="color" THEN e.entity_id END) LEFT JOIN erp_ink_color_details cd ON cd.color_id=c.id LEFT JOIN erp_customers cu ON cu.id=cd.customer_id LEFT JOIN erp_finished_products ar ON ar.id=cd.article_id WHERE 1=1';$args=[];
    if(!empty($_GET['q'])){$sql.=' AND COALESCE(b.code,"")||" "||COALESCE(b.lot,"")||" "||COALESCE(m.code,"")||" "||COALESCE(f.family,"")||" "||COALESCE(c.code,"")||" "||COALESCE(c.name,"")||" "||COALESCE(c.pantone,"")||" "||u.name||" "||COALESCE(cu.name,"")||" "||COALESCE(ar.code,"") LIKE ?';$args[]='%'.$_GET['q'].'%';}
    foreach(['order_id'=>'e.production_order_id','container_id_filter'=>'e.container_id'] as $key=>$col)if(!empty($_GET[$key])){$sql.=' AND '.$col.'=?';$args[]=(int)$_GET[$key];}
    if(!empty($_GET['from'])){$sql.=' AND e.created_at>=?';$args[]=$_GET['from'].' 00:00:00';}if(!empty($_GET['to'])){$sql.=' AND e.created_at<=?';$args[]=$_GET['to'].' 23:59:59';}
    $rows=$ink->query($sql.' ORDER BY e.id DESC LIMIT 300',$args);
    foreach($rows as $e){echo '<article class="card card-body mb-3"><strong>'.h($e['action'].' · '.$e['created_at'].' · '.$e['operator']).'</strong><p>'.h($e['container_code'].' · '.$e['mix_code'].' · OF '.$e['production_order_id'].' · '.ColorimetryService::grams($e['delta_mg']).' g').'</p><details><summary>Detalhes e composição registada</summary><pre>'.h(json_encode(json_decode($e['payload_json'],true),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)).'</pre></details></article>';}
}else{
    $q=trim($_GET['q']??'');?><form method="get" class="mb-3"><label class="form-label w-100">Código TISSER, Pantone, nome, cliente ou artigo<input autofocus class="form-control form-control-lg" name="q" value="<?=h($q)?>"></label><button class="btn btn-primary btn-lg">Pesquisar cor</button></form><?php
    $rows=$ink->query('SELECT c.*,d.hex,d.status,d.substrate,d.notes,cu.name customer,ar.code article FROM erp_colors c LEFT JOIN erp_ink_color_details d ON d.color_id=c.id LEFT JOIN erp_customers cu ON cu.id=d.customer_id LEFT JOIN erp_finished_products ar ON ar.id=d.article_id WHERE c.code||" "||c.name||" "||COALESCE(c.pantone,"")||" "||COALESCE(cu.name,"")||" "||COALESCE(ar.code,"") LIKE ? ORDER BY c.code LIMIT 200',['%'.$q.'%']);
    foreach($rows as $c){echo '<article class="card card-body mb-3"><h2 class="h4">';if(preg_match('/^#[a-fA-F0-9]{6}$/D',(string)$c['hex']))echo '<span class="swatch" style="background:'.h($c['hex']).'" title="Referência visual aproximada"></span> ';echo h($c['code'].' — '.$c['name']).'</h2><p>'.h('Pantone '.$c['pantone'].' · '.$c['status'].' · '.$c['substrate'].' · '.$c['customer'].' · '.$c['article']).'</p><p>'.h($c['notes']).'</p>';
        $formulas=$ink->query('SELECT * FROM erp_ink_formulas WHERE color_id=? AND status="Aprovada" ORDER BY version DESC',[$c['id']]);foreach($formulas as $f){echo '<details class="mb-2"><summary>'.h($f['family'].' V'.$f['version'].' · '.$f['substrate']).'</summary><p>'.h($f['notes']).'</p>';$attachments=$ink->query('SELECT id,name FROM erp_ink_formula_attachments WHERE formula_id=?',[$f['id']]);foreach($attachments as $a)echo '<p><a href="ink_formula_attachment.php?id='.$a['id'].'">'.h($a['name']).'</a></p>';$cs=json_decode($f['components_json'],true);$total=array_sum(array_column($cs,'mg'));foreach($cs as $comp)echo '<p>'.h($comp['code'].' — '.$comp['name'].' · '.ink_grams($comp['mg']).' g · '.number_format($comp['mg']/$total*100,4,',','').'%').'</p>';echo '<a class="btn btn-primary btn-lg" href="?tab=prepare&formula_id='.$f['id'].'">Preparar esta fórmula</a></details>';}
        if($ink->can('ink.formula.create'))echo '<a class="btn btn-outline-secondary" href="?tab=library&color_id='.$c['id'].'">Gerir cor</a>';echo '</article>';
    }
    if(!empty($inkUser['is_admin'])){$r=$ink->query('SELECT (SELECT COUNT(*) FROM erp_colors) colors,(SELECT COUNT(*) FROM erp_ink_formulas WHERE status="Aprovada") formulas,(SELECT COUNT(*) FROM erp_ink_mixes WHERE created_at>=date("now","start of month")) mixes,(SELECT COALESCE(SUM(actual_mg),0) FROM erp_ink_mixes WHERE created_at>=date("now","start of month")) prepared,(SELECT COALESCE(SUM(available_mg),0) FROM erp_ink_containers) stock')[0];echo '<div class="card card-body"><h2 class="h5">Indicadores</h2><p>'.h($r['colors'].' cores · '.$r['formulas'].' fórmulas aprovadas · '.$r['mixes'].' preparações este mês').'</p><p>'.h(ink_grams($r['prepared']).' g preparados este mês · '.ColorimetryService::grams($r['stock']).' g em stock físico').'</p><a href="erp.php?page=settings">Configuração ERP / impressoras e modelos</a></div>';}
}
?>
</main><script src="assets/js/colorimetry.js"></script><?php require __DIR__.'/partials/footer.php'; ?>
