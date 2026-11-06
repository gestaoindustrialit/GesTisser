<?php
require_once __DIR__.'/helpers.php';
require_once __DIR__.'/erp_migrations.php';
require_once __DIR__.'/app/Services/InkMaterialSchema.php';
require_once __DIR__.'/app/Services/PurchaseOrderEmail.php';
require_login();$user=current_user($pdo)?:[];
if(!gt_erp_user_can($pdo,$user,'erp.view')||!gt_erp_user_can($pdo,$user,'erp.purchases')){http_response_code(403);exit('Acesso reservado às compras.');}
$method=(string)($_SERVER['REQUEST_METHOD']??'GET');
if(!in_array($method,['GET','HEAD','POST'],true)){header('Allow: GET, HEAD, POST');http_response_code(405);exit('Método não permitido.');}
$isPost=$method==='POST';$id=(int)($_POST['purchase_order_id']??$_GET['purchase_order_id']??0);
if($isPost&&!validate_csrf_or_abort(false)){http_response_code(419);exit('Pedido inválido.');}
try {
    gt_erp_run_phase1_migrations($pdo);
    $archive=(int)($_GET['archive_id']??0);
    if($archive&&!$isPost){
        $q=$pdo->prepare('SELECT pdf_content,pdf_sha256 FROM erp_purchase_order_emails WHERE id=?');$q->execute([$archive]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new OutOfBoundsException('Documento não encontrado.');
        $pdf=is_resource($row['pdf_content'])?stream_get_contents($row['pdf_content']):$row['pdf_content'];
        if(!is_string($pdf)||!hash_equals((string)$row['pdf_sha256'],hash('sha256',$pdf)))throw new RuntimeException('O PDF arquivado não passou na verificação de integridade.');
    }else{
        if($id<1)throw new RuntimeException('Selecione uma encomenda guardada.');
        InkMaterialSchema::ensure($pdo);
        if($isPost){
            PurchaseOrderEmail::send($pdo,$_POST,(int)$user['id'],function($recipient,$subject,$body,$attachments){
                $sent=deliver_report($recipient,$subject,$body,null,$attachments);
                return ['sent'=>$sent,'error'=>$sent?'':taskforce_last_delivery_error()];
            });
            unset($_SESSION['purchase_order_email_drafts'][$id],$_SESSION['purchase_order_email_notice']);
            header('Location: erp.php?page=orders&purchase_order_id='.$id.'#purchase-email-history',true,303);exit;
        }
        $pdf=PurchaseOrderDocument::pdf(PurchaseOrderDocument::snapshot($pdo,$id));
    }
    header('Content-Type: application/pdf');header('Content-Disposition: inline; filename="encomenda.pdf"');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');echo $pdf;
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    $errorId=taskforce_error_id();safe_log('Purchase document failed',['error_id'=>$errorId,'purchase_order_id'=>$id,'exception'=>get_class($e),'message'=>$e->getMessage(),'file'=>$e->getFile(),'line'=>$e->getLine()]);
    $expected=$e instanceof RuntimeException&&!($e instanceof PDOException);
    $message=$expected?$e->getMessage():'Não foi possível preparar o documento da encomenda.';
    if($isPost&&$id>0){
        $_SESSION['purchase_order_email_drafts'][$id]=['recipient'=>(string)($_POST['recipient']??''),'subject'=>(string)($_POST['subject']??''),'body'=>(string)($_POST['body']??'')];
        $_SESSION['purchase_order_email_notice']=['order_id'=>$id,'message'=>$message.' (referência '.$errorId.')'];
        header('Location: erp.php?page=orders&purchase_order_id='.$id.'&compose_email=1#purchase-email-compose',true,303);exit;
    }
    http_response_code($e instanceof OutOfBoundsException?404:($expected?400:500));header('Content-Type: text/html; charset=utf-8');
    echo '<p>'.h($message).' (referência '.h($errorId).')</p><p><a href="erp.php?page=orders'.($id>0?'&amp;purchase_order_id='.$id:'').'">Voltar às encomendas</a></p>';
}
