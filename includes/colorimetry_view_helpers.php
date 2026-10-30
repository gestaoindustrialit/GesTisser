<?php
/** Shared fragment controls. All writes continue through ColorimetryService. */
function ink_url(array $params=[])
{
    global $productionOrderId;
    if($productionOrderId&&!isset($params['production_order_id']))$params['production_order_id']=$productionOrderId;
    $params['modal']=1;return 'colorimetry.php?'.http_build_query($params);
}
function ink_link(array $params,$text,$class='btn btn-outline-primary',$icon='')
{
    echo '<a class="'.h($class).'" href="'.h(ink_url($params)).'">'.($icon?'<i class="bi '.h($icon).'" aria-hidden="true"></i> ':'').h($text).'</a>';
}
function ink_form($action,$class='ink-form')
{
    $GLOBALS['inkFormAction']=$action;
    $GLOBALS['inkRestore']=($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')===$action);
    $key=$GLOBALS['inkRestore']?($_POST['request_key']??bin2hex(random_bytes(16))):bin2hex(random_bytes(16));
    echo '<form method="post" enctype="multipart/form-data" class="'.h($class).'" data-ink-form="'.h($action).'">'.csrf_input().'<input type="hidden" name="request_key" value="'.h($key).'"><input type="hidden" name="action" value="'.h($action).'">';
}
function ink_value($name,$default)
{
    if(empty($GLOBALS['inkRestore']))return $default;
    $value=$_POST['data']??[];foreach(explode('][',$name) as $part){if(!is_array($value)||!array_key_exists($part,$value))return $default;$value=$value[$part];}
    return is_scalar($value)?$value:$default;
}
function ink_hidden($name,$value)
{
    if(in_array($name,['id','formula_id','container_id'],true)&&!empty($GLOBALS['inkRestore'])&&(string)($_POST['data'][$name]??'')!==(string)$value)$GLOBALS['inkRestore']=false;
    echo '<input type="hidden" name="data['.h($name).']" value="'.h((string)$value).'">';
}
function ink_input($name,$label,$value='',$type='text',$required=false)
{
    $value=ink_value($name,$value);$wide=in_array($name,['notes','measurements'],true);
    echo '<label class="ink-field'.($wide?' ink-field-wide':'').'">';if($label!=='')echo '<span>'.h($label).($required?' <small aria-hidden="true">*</small>':'').'</span>';
    if($wide)echo '<textarea class="form-control" rows="2" name="data['.h($name).']" '.($required?'required':'').'>'.h((string)$value).'</textarea>';
    else echo '<input class="form-control" aria-label="'.h($label).'" name="data['.h($name).']" type="'.h($type).'" value="'.h((string)$value).'" '.($required?'required':'').($type==='number'?' step="0.001" min="0" inputmode="decimal"':'').'>';
    echo '</label>';
}
function ink_select($name,$label,array $rows,$value='',$blank=true)
{
    $value=ink_value($name,$value);echo '<label class="ink-field">';if($label!=='')echo '<span>'.h($label).'</span>';
    echo '<select data-searchable-select="off" class="form-select" aria-label="'.h($label).'" name="data['.h($name).']">';if($blank)echo '<option value="">Selecionar…</option>';
    foreach($rows as $r)echo '<option value="'.h((string)$r['id']).'" '.(isset($r['material_id'])?'data-material-id="'.(int)$r['material_id'].'" ':'').((string)$r['id']===(string)$value?'selected':'').'>'.h($r['label']).'</option>';echo '</select></label>';
}
function ink_unit($name='unit'){ink_select($name,'Unidade',[['id'=>'g','label'=>'g'],['id'=>'kg','label'=>'kg']],'g',false);}
function ink_grams($mg){return number_format($mg/1000,3,',',' ');}
function ink_mass($mg){return number_format($mg/1000000,3,',',' ').' kg';}
function ink_badge($status)
{
    $tone=in_array($status,['Aprovada','Aprovado','Disponível'],true)?'green':(in_array($status,['Inativa','Rejeitado','Bloqueado','Esgotado'],true)?'muted':'amber');
    echo '<span class="ink-badge ink-badge-'.$tone.'">'.h($status?:'Sem classificação').'</span>';
}
function ink_swatch($hex,$large=false)
{
    $valid=preg_match('/^#[a-fA-F0-9]{6}$/D',(string)$hex);echo '<span class="ink-swatch'.($large?' ink-swatch-lg':'').($valid?'':' ink-swatch-neutral').'"'.($valid?' style="background:'.h($hex).'"':'').' title="Referência visual aproximada">'.($valid?'':'<i class="bi bi-palette" aria-hidden="true"></i>').'</span>';
}
function ink_empty($title,$description,$icon='bi-search')
{
    echo '<div class="ink-empty"><i class="bi '.h($icon).'" aria-hidden="true"></i><h3>'.h($title).'</h3><p>'.h($description).'</p></div>';
}
function ink_heading($title,$subtitle='')
{
    echo '<div class="ink-section-heading"><div><h2>'.h($title).'</h2>'.($subtitle?'<p>'.h($subtitle).'</p>':'').'</div></div>';
}
function ink_submit($text)
{
    echo '<div class="ink-form-footer"><span class="ink-required-note">* Campos obrigatórios</span><button type="submit" class="btn btn-primary"><i class="bi bi-check2" aria-hidden="true"></i> '.h($text).'</button></div></form>';
}
function ink_composition(array $components)
{
    $total=array_sum(array_column($components,'mg'));echo '<div class="ink-table-wrap"><table class="table ink-table"><thead><tr><th>Componente</th><th class="text-end">Massa (g)</th><th class="text-end">Composição</th></tr></thead><tbody>';
    foreach($components as $c)echo '<tr><td><strong>'.h($c['code']).'</strong><small>'.h($c['name']).'</small></td><td class="text-end ink-number">'.h(ink_grams($c['mg'])).'</td><td class="text-end">'.h(number_format($total?$c['mg']/$total*100:0,3,',',' ')).'%</td></tr>';
    echo '</tbody><tfoot><tr><th>Total</th><th class="text-end">'.h(ink_grams($total)).' g</th><th class="text-end">100%</th></tr></tfoot></table></div>';
}
function ink_components(array $materials,array $components=[],$actual=false,$stock=false)
{
    global $ink;
    $sourceRows=[];$labelRows=[];
    if($actual||$stock){
        $sourceRows=$ink->query('SELECT b.rowid id,b.item_id material_id,r.code||" · "||COALESCE(b.lot,"sem lote")||" · "||(b.physical_qty-b.reserved_qty-b.blocked_qty)||" "||u.code label FROM erp_stock_balances b JOIN erp_raw_materials r ON r.id=b.item_id JOIN erp_units u ON u.id=r.primary_unit_id WHERE b.item_type="raw_material" AND b.physical_qty-b.reserved_qty-b.blocked_qty>0 ORDER BY r.code,b.lot');
        $labelRows=$ink->query('SELECT l.id,l.raw_material_id material_id,r.code||" · "||l.barcode||" · "||l.weight_kg||" kg" label FROM erp_raw_material_ink_labels l JOIN erp_raw_materials r ON r.id=l.raw_material_id WHERE l.weight_kg>0 AND l.status NOT IN ("IN_USE","BLOCKED","CONSUMED") ORDER BY r.code,l.barcode');
    }
    $rows=$components?:($stock?[[]]:[[],[],[]]);
    if(!empty($GLOBALS['inkRestore'])&&!empty($_POST['data']['components'])){$rows=[];foreach($_POST['data']['components'] as $key=>$posted)$rows[$key]=['material_id'=>$posted['material_id']??''];}
    $base=$components?array_sum(array_column($components,'mg')):0;
    echo '<div data-components class="ink-components'.($actual?' ink-weighing':'').'"><div class="ink-table-wrap"><table class="table ink-table"><thead><tr><th>Matéria-prima</th>'.($actual?'<th class="text-end">A pesar (g)</th>':'').'<th>Massa'.($actual?' real':'').'</th><th>Unidade</th><th class="ink-remove-col"><span class="visually-hidden">Remover</span></th></tr></thead><tbody data-component-rows>';
    foreach($rows as $i=>$c){
        echo '<tr data-component-row><td>';ink_select('components]['.$i.'][material_id','Matéria-prima',$materials,$c['material_id']??'');
        if($actual||$stock){echo '<div class="ink-source-fields">';ink_select('components]['.$i.'][source_balance_id','Stock / lote de origem',$sourceRows);if($labelRows)ink_select('components]['.$i.'][source_label_id','Lata de origem (se etiquetada)',$labelRows);echo '</div>';}
        echo '</td>';if($actual)echo '<td class="ink-number" data-calculate-mg="'.(int)($components[$i]['mg']??0).'" data-base-mg="'.$base.'">—</td>';
        echo '<td class="ink-mass-cell">';ink_input('components]['.$i.'][quantity','Massa'.($actual?' real':''),$actual?'':(isset($c['mg'])?ColorimetryService::grams($c['mg']):''),'number',$actual);echo '</td><td class="ink-unit-cell">';ink_unit('components]['.$i.'][unit');echo '</td><td>';
        if(!$actual)echo '<button type="button" class="ink-remove" data-remove-row aria-label="Remover componente"><i class="bi bi-x-lg" aria-hidden="true"></i></button>';echo '</td></tr>';
    }
    echo '</tbody></table></div>';if(!$actual)echo '<button type="button" class="btn btn-outline-primary ink-add" data-add-component><i class="bi bi-plus-lg" aria-hidden="true"></i> Adicionar componente</button>';echo '</div>';
}
