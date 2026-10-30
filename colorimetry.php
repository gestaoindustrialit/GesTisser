<?php
require_once __DIR__.'/helpers.php';
require_once __DIR__.'/app/Services/ColorimetryService.php';
require_once __DIR__.'/app/Services/CentralPrinting.php';
require_login();gt_erp_run_phase1_migrations($pdo);ColorimetryService::migrate($pdo);
$inkUser=current_user($pdo);$ink=new ColorimetryService($pdo,$inkUser);
try{$ink->authorize();}catch(Throwable $e){http_response_code(403);exit('Sem permissão para Tintas / Cores.');}
header('Cache-Control: no-store');$error='';$success='';$tab=$_GET['tab']??'search';$containerId=(int)($_GET['container_id']??0);$formulaId=(int)($_GET['formula_id']??0);$productionOrderId=(int)($_GET['production_order_id']??0);
if($tab==='library'&&!$ink->can('ink.formula.create')&&!$ink->can('ink.formula.approve')){http_response_code(403);exit('Sem permissão de gestão da biblioteca.');}
if($_SERVER['REQUEST_METHOD']==='GET'&&($_GET['modal']??'')!=='1'){
    $open=$_GET;unset($open['modal']);redirect('shopfloor.php?ink_view='.rawurlencode(http_build_query($open)));
}
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
        redirect($url.'&modal=1');
    }catch(Throwable $e){$error=$e->getMessage();if((int)$e->getCode()===403)http_response_code(403);}
}

header('X-Ink-Fragment: 1');
require __DIR__.'/includes/colorimetry_view_helpers.php';
ob_start();
try { require __DIR__.'/includes/colorimetry_views.php'; echo ob_get_clean(); } catch (Throwable $e) { ob_end_clean(); http_response_code(422); echo '<section id="ink-module"><div class="alert alert-danger" role="alert">'.h($e->getMessage()).'</div><a class="btn btn-outline-primary" href="colorimetry.php?modal=1">Voltar às cores</a></section>'; }
