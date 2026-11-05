<?php
require_once __DIR__.'/helpers.php';
require_once __DIR__.'/erp_migrations.php';
require_once __DIR__.'/app/Services/PurchaseOrderEmail.php';
require_login();$user=current_user($pdo)?:[];
if(!gt_erp_user_can($pdo,$user,'erp.view')||!gt_erp_user_can($pdo,$user,'erp.purchases')){http_response_code(403);exit('Acesso reservado às compras.');}
gt_erp_run_phase1_migrations($pdo);
try {
    if($_SERVER['REQUEST_METHOD']==='POST'&&!validate_csrf_or_abort(false)){http_response_code(419);exit('Pedido inválido.');}
    $archive=(int)($_GET['archive_id']??0);
    if($archive){$q=$pdo->prepare('SELECT pdf_content,pdf_sha256 FROM erp_purchase_order_emails WHERE id=?');$q->execute([$archive]);$row=$q->fetch(PDO::FETCH_ASSOC);if(!$row){http_response_code(404);exit('Documento não encontrado.');}$pdf=$row['pdf_content'];if(!hash_equals($row['pdf_sha256'],hash('sha256',$pdf)))throw new RuntimeException('Documento arquivado inválido.');}
    else {
        $id=(int)($_POST['purchase_order_id']??$_GET['purchase_order_id']??0);
        if($_SERVER['REQUEST_METHOD']==='POST'){
            PurchaseOrderEmail::send($pdo,$_POST,(int)$user['id'],function($recipient,$subject,$body,$attachments){
                $sent=deliver_report($recipient,$subject,$body,null,$attachments);
                return ['sent'=>$sent,'error'=>$sent?'':taskforce_last_delivery_error()];
            });
            header('Location: erp.php?page=orders&purchase_order_id='.$id.'#purchase-email-history',true,303);exit;
        }
        $pdf=PurchaseOrderDocument::pdf(PurchaseOrderDocument::snapshot($pdo,$id));
    }
    header('Content-Type: application/pdf');header('Content-Disposition: inline; filename="encomenda.pdf"');header('Cache-Control: private, no-store');echo $pdf;
}catch(Throwable $e){http_response_code(400);echo h($e->getMessage());}
