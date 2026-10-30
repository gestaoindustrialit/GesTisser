<?php
require_once __DIR__.'/helpers.php';
require_once __DIR__.'/app/Services/CentralPrinting.php';
require_once __DIR__.'/app/Services/PrintLabelRenderer.php';
require_once __DIR__.'/app/Services/RawMaterialRollLabelService.php';
require_once __DIR__.'/app/Services/RawMaterialInkLabelService.php';
require_once __DIR__.'/app/Services/ColorimetryService.php';
require_login();header('Cache-Control: no-store');
$printing=new CentralPrinting($pdo);$user=current_user($pdo)?:[];
if(($_GET['kind']??'')==='prepared_ink'&&!gt_erp_user_can($pdo,$user,'ink.prepare')){http_response_code(403);exit('Sem permissão.');}
if(!$printing->enabled()){http_response_code(503);exit('Impressão central desativada. Utilize a impressão habitual.');}
if(!(($_GET['kind']??'')==='prepared_ink'&&gt_erp_user_can($pdo,$user,'ink.prepare'))&&(int)($user['is_admin']??0)!==1&&!in_array((string)($user['access_profile']??''),['Utilizador','Produção','Chefias','RH'],true)){http_response_code(403);exit('Sem permissão de impressão.');}
$center=(int)($_SESSION['shopfloor_work_center_id']??0);$kind=(string)($_GET['kind']??'');$id=(int)($_GET['id']??0);$error='';$job=null;$inkModal=$kind==='prepared_ink'&&($_GET['modal']??'')==='1';
try{
    if($kind==='prepared_ink'&&!$inkModal&&$_SERVER['REQUEST_METHOD']==='GET')redirect('prepared_ink_label.php?container_id='.$id);
    if(!$center)throw new RuntimeException('Selecione primeiro o posto no Shopfloor.');
    if(!in_array($kind,['roll','ink','prepared_ink'],true))throw new InvalidArgumentException('Tipo inválido.');
    if($kind==='prepared_ink') {
        require_once __DIR__.'/app/Services/ColorimetryService.php';
        ColorimetryService::migrate($pdo);$inkService=new ColorimetryService($pdo,$user);$inkService->authorize();$label=$inkService->container($id);
    } else {$source=$kind==='roll'?new RawMaterialRollLabelService($pdo):new RawMaterialInkLabelService($pdo);$label=$source->find($id);if(!$label)throw new RuntimeException('Etiqueta não encontrada.');}
    $s=$pdo->prepare('SELECT t.*,f.width_mm,f.height_mm,f.orientation FROM erp_print_templates t JOIN erp_print_formats f ON f.id=t.format_id WHERE t.renderer=? AND t.active=1 ORDER BY t.id');$s->execute([$kind==='prepared_ink'?'prepared_ink_label':($kind==='roll'?'raw_material_roll_label':'raw_material_ink_label')]);$templates=$s->fetchAll(PDO::FETCH_ASSOC);
    if(!$templates)throw new RuntimeException('Configure um modelo para esta etiqueta na Configuração ERP.');
    $tid=(int)($_POST['template_id']??$_GET['template_id']??$templates[0]['id']);$template=null;foreach($templates as $t)if((int)$t['id']===$tid)$template=$t;if(!$template)throw new InvalidArgumentException('Modelo inválido.');
    $options=$printing->choices($center,$template['type_id'],$template['format_id']);
    if($_SERVER['REQUEST_METHOD']==='POST'){
        if(!validate_csrf_or_abort(false))throw new RuntimeException('Pedido inválido.');
        $printer=(int)($_POST['printer_id']??0);$selected=null;foreach($options as $p)if(($printer&&$p['id']==$printer&&$p['manual_allowed'])||(!$printer&&($p['is_default']||count($options)===1))){$selected=$p;break;}
        if(!$selected)throw new RuntimeException('Sem impressora compatível.');
        $mime=$selected['language']==='zpl'?'application/zpl':'application/pdf';$content=$mime==='application/zpl'?PrintLabelRenderer::zpl($label,$kind,$template,(int)$selected['dpi']):PrintLabelRenderer::pdf($label,$kind,$template);
        $job=$printing->enqueue((int)$user['id'],$center,$template['id'],$printer,(int)($_POST['copies']??1),$content,$mime,(string)($_POST['request_key']??''),(int)$selected['dpi']);
        if($kind==='prepared_ink')$inkService->execute('print',['container_id'=>$id,'job_id'=>$job],(string)$_POST['request_key']);
    }
}catch(Throwable $e){$error=$e->getMessage();}
$pageTitle='Impressão centralizada';if(!$inkModal)require __DIR__.'/partials/header.php';
?><section id="ink-module" data-ink-view="print"><div class="<?=$inkModal?'ink-content':'container py-4'?>"><article class="<?=$inkModal?'ink-sheet':''?>"><h1 class="h3">Imprimir etiqueta</h1><?php if($error):?><div class="alert alert-danger"><?=h($error)?></div><?php endif;?><?php if($job):?><div class="alert alert-success">Trabalho colocado na fila: <?=h($job)?>. A aceitação pelo spooler não confirma a saída física.</div><a href="<?=$inkModal?'colorimetry.php?modal=1&container_id='.$id:'shopfloor.php'?>" class="btn btn-primary btn-lg">Voltar</a><?php elseif(isset($template)):?>
<form method="get" class="mb-3"><input type="hidden" name="kind" value="<?=h($kind)?>"><input type="hidden" name="id" value="<?=$id?>"><label class="form-label">Modelo / formato</label><select class="form-select form-select-lg" name="template_id" <?=$inkModal?'':'onchange="this.form.submit()"'?>><?php foreach($templates as $t):?><option value="<?=$t['id']?>" <?=$t['id']==$template['id']?'selected':''?>><?=h($t['name'])?></option><?php endforeach;?></select><?php if($inkModal):?><button class="btn btn-outline-primary mt-2" type="submit">Selecionar modelo</button><?php else:?><noscript><button>Selecionar</button></noscript><?php endif;?></form>
<form method="post"><?=csrf_input()?><input type="hidden" name="request_key" value="<?=h((string)($_POST['request_key']??bin2hex(random_bytes(16))))?>"><input type="hidden" name="template_id" value="<?=$template['id']?>"><p><?=h((string)($label['barcode']??$label['code']))?> · <?=h((string)($label['article_code']??$label['color_code']))?></p>
<?php if(count($options)>1):?><label class="form-label">Impressora</label><select class="form-select form-select-lg mb-3" name="printer_id"><option value="0">Predefinida do posto</option><?php foreach($options as $p):if(!$p['manual_allowed'])continue;?><option value="<?=$p['id']?>"><?=h($p['name'])?> · <?=($p['online']&&strtotime((string)$p['last_seen'].' UTC')>time()-120)?'Online':'Offline / aguarda agente'?></option><?php endforeach;?></select><?php elseif($options):?><p>Impressora: <strong><?=h($options[0]['name'])?></strong> · <?=$options[0]['online']?'Online':'Offline / aguarda agente'?></p><?php else:?><div class="alert alert-warning">Não existe impressora compatível configurada para este posto.</div><?php endif;?>
<label class="form-label">Cópias</label><input class="form-control form-control-lg mb-3" type="number" name="copies" min="1" max="100" value="1" required><button type="submit" class="btn btn-primary btn-lg w-100" <?=$options?'':'disabled'?>>Enviar para impressão</button></form><?php endif;?></article></div></section><?php if(!$inkModal)require __DIR__.'/partials/footer.php';?>
