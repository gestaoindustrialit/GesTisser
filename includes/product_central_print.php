<?php
require_once __DIR__.'/../app/Services/CentralPrinting.php';
require_once __DIR__.'/../app/Services/PrintLabelRenderer.php';
$productPrinting=new CentralPrinting($pdo);
if($autoPrint&&$productPrinting->enabled()){
    header('Cache-Control: no-store');$centralError='';$centralJob=null;$center=(int)($_SESSION['shopfloor_work_center_id']??0);$productOptions=[];$productTemplate=null;
    try{
        if(!$center)throw new RuntimeException('Selecione o posto no Shopfloor.');
        $s=$pdo->query("SELECT t.* FROM erp_print_templates t JOIN erp_print_formats f ON f.id=t.format_id WHERE t.renderer='product_label' AND t.active=1 AND f.width_mm=210 AND f.height_mm=297 ORDER BY t.id LIMIT 1");$productTemplate=$s->fetch(PDO::FETCH_ASSOC);
        if(!$productTemplate)throw new RuntimeException('Configure um modelo de produto acabado com formato A4.');
        $productOptions=$productPrinting->choices($center,$productTemplate['type_id'],$productTemplate['format_id']);
        $productOptions=array_values(array_filter($productOptions,function($p){return $p['language']==='driver';}));
        if(!$productOptions)throw new RuntimeException('Sem fila PDF/A4 compatível neste posto.');
        if(count($productOptions)===1||!empty($_POST['central_confirm'])){
            $content=PrintLabelRenderer::productPdf($data,$values,(string)app_setting($pdo,'company_name','TISSER'));
            $requestKey=(string)($_POST['central_request_key']??'');
            if(!preg_match('/^[a-f0-9]{32}$/D',$requestKey))throw new RuntimeException('Atualize o Shopfloor antes de enviar o trabalho.');
            $centralJob=$productPrinting->enqueue((int)$user['id'],$center,$productTemplate['id'],(int)($_POST['printer_id']??0),$values['copies'],$content,'application/pdf',$requestKey);
        }
    }catch(Throwable $e){$centralError=$e->getMessage();}
    $pageTitle='Imprimir etiqueta de produto';require __DIR__.'/../partials/header.php';?>
    <main class="container py-4"><h1 class="h3">Etiqueta de produto</h1><?php if($centralError):?><div class="alert alert-danger"><?=h($centralError)?></div><?php endif;?><?php if($centralJob):?><div class="alert alert-success">Trabalho colocado na fila: <?=h($centralJob)?>.</div><p>A aceitação pelo spooler não confirma impressão física.</p><a class="btn btn-primary btn-lg" href="shopfloor.php">Voltar ao posto</a><?php elseif($productOptions):?><form method="post"><?=csrf_input()?><?php foreach(['production_order_id','operation_id','quantity','copies','label_date','central_request_key'] as $field):?><input type="hidden" name="<?=$field?>" value="<?=h((string)($_POST[$field]??''))?>"><?php endforeach;?><input type="hidden" name="mode" value="print"><input type="hidden" name="central_confirm" value="1"><label class="form-label">Impressora</label><select class="form-select form-select-lg mb-3" name="printer_id"><option value="0">Predefinida do posto</option><?php foreach($productOptions as $p):if(!$p['manual_allowed'])continue;?><option value="<?=$p['id']?>"><?=h($p['name'])?> · <?=$p['online']?'Online':'Offline'?></option><?php endforeach;?></select><button class="btn btn-primary btn-lg w-100">Enviar para impressão</button></form><?php endif;?></main><?php require __DIR__.'/../partials/footer.php';exit;
}
