<?php
require_once __DIR__.'/helpers.php';require_once __DIR__.'/app/Services/ColorimetryService.php';require_once __DIR__.'/app/Services/CentralPrinting.php';require_once __DIR__.'/app/Services/PreparedInkLabelRenderer.php';
require_login();gt_erp_run_phase1_migrations($pdo);ColorimetryService::migrate($pdo);$ink=new ColorimetryService($pdo,current_user($pdo));
try{$ink->authorize();}catch(Throwable $e){http_response_code(403);exit('Sem permissão.');}
header('Cache-Control: no-store');$id=(int)($_GET['container_id']??0);$error='';$inkModal=($_GET['modal']??'')==='1';
try{
    $b=$ink->container($id);
    if(!$inkModal&&$_SERVER['REQUEST_METHOD']==='GET')redirect('colorimetry.php?container_id='.$id);
    if((new CentralPrinting($pdo))->enabled())redirect('print_label.php?kind=prepared_ink&id='.$id.($inkModal?'&modal=1':''));
    if($_SERVER['REQUEST_METHOD']==='POST'){
        if(!validate_csrf_or_abort(false))throw new RuntimeException('Pedido inválido.');
        $pdf=PreparedInkLabelRenderer::pdf($b,['width_mm'=>100,'height_mm'=>50]);
        $ink->execute('print',['container_id'=>$id,'mode'=>'browser_pdf'],(string)($_POST['request_key']??''));
        header('Content-Type: application/pdf');header('Content-Disposition: inline; filename="'.$b['code'].'.pdf"');echo $pdf;exit;
    }
}catch(Throwable $e){$error=$e->getMessage();}
$pageTitle='Etiqueta de tinta preparada';if(!$inkModal)require __DIR__.'/partials/header.php';?>
<section id="ink-module" data-ink-view="print"><div class="ink-content"><article class="ink-sheet"><div class="ink-section-heading"><div><h2>Imprimir etiqueta</h2><p>Identificação do recipiente · 100 × 50 mm</p></div></div><?php if($error):?><div class="alert alert-danger"><?=h($error)?></div><?php endif;?><?php if(isset($b)):?><p><?=h($b['code'].' · '.$b['color_code'].' — '.$b['color_name'])?></p><p>Formato 100 × 50 mm. Imprima o PDF à escala de 100%. A reimpressão conserva o recipiente e o stock.</p><form method="post" class="ink-form"><?=csrf_input()?><input type="hidden" name="request_key" value="<?=h(bin2hex(random_bytes(16)))?>"><button type="submit" class="btn btn-primary">Gerar etiqueta PDF</button></form><a class="btn btn-outline-secondary btn-lg mt-3" href="colorimetry.php?container_id=<?=$id?>">Voltar ao recipiente</a><?php endif;?></article></div></section><?php if(!$inkModal)require __DIR__.'/partials/footer.php'; ?>
