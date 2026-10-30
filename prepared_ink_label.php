<?php
require_once __DIR__.'/helpers.php';require_once __DIR__.'/app/Services/ColorimetryService.php';require_once __DIR__.'/app/Services/CentralPrinting.php';require_once __DIR__.'/app/Services/PreparedInkLabelRenderer.php';
require_login();gt_erp_run_phase1_migrations($pdo);ColorimetryService::migrate($pdo);$ink=new ColorimetryService($pdo,current_user($pdo));
try{$ink->authorize();}catch(Throwable $e){http_response_code(403);exit('Sem permissão.');}
header('Cache-Control: no-store');$id=(int)($_GET['container_id']??0);$error='';
try{
    $b=$ink->container($id);
    if((new CentralPrinting($pdo))->enabled())redirect('print_label.php?kind=prepared_ink&id='.$id);
    if($_SERVER['REQUEST_METHOD']==='POST'){
        if(!validate_csrf_or_abort(false))throw new RuntimeException('Pedido inválido.');
        $pdf=PreparedInkLabelRenderer::pdf($b,['width_mm'=>100,'height_mm'=>50]);
        $ink->execute('print',['container_id'=>$id,'mode'=>'browser_pdf'],(string)($_POST['request_key']??''));
        header('Content-Type: application/pdf');header('Content-Disposition: inline; filename="'.$b['code'].'.pdf"');echo $pdf;exit;
    }
}catch(Throwable $e){$error=$e->getMessage();}
$pageTitle='Etiqueta de tinta preparada';require __DIR__.'/partials/header.php';?>
<main class="container py-4"><h1 class="h3">Imprimir etiqueta de tinta preparada</h1><?php if($error):?><div class="alert alert-danger"><?=h($error)?></div><?php endif;?><?php if(isset($b)):?><p><?=h($b['code'].' · '.$b['color_code'].' — '.$b['color_name'])?></p><p>Formato 100 × 50 mm. Imprima o PDF à escala de 100%. A reimpressão conserva o recipiente e o stock.</p><form method="post"><?=csrf_input()?><input type="hidden" name="request_key" value="<?=h(bin2hex(random_bytes(16)))?>"><button class="btn btn-primary btn-lg">Gerar etiqueta PDF / reimprimir</button></form><a class="btn btn-outline-secondary btn-lg mt-3" href="colorimetry.php?container_id=<?=$id?>">Voltar ao recipiente</a><?php endif;?></main><?php require __DIR__.'/partials/footer.php'; ?>
