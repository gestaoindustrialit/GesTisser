<?php
require_once __DIR__.'/PurchaseOrderDocument.php';
/** Archive before contacting the transport; one submission key can send at most once. */
final class PurchaseOrderEmail
{
    public static function send(PDO $pdo, array $data, int $userId, callable $transport): int
    {
        if($pdo->inTransaction())throw new RuntimeException('O envio deve começar fora de uma transação.');
        $id=(int)($data['purchase_order_id']??0);
        $recipient=trim((string)($data['recipient']??''));
        $subject=trim((string)($data['subject']??''));
        $body=trim((string)($data['body']??''));
        $key=(string)($data['dispatch_key']??'');
        if(!preg_match('/^[a-f0-9]{32}$/D',$key))throw new RuntimeException('Pedido de envio inválido. Atualize a encomenda.');
        if(!filter_var($recipient,FILTER_VALIDATE_EMAIL)||$subject===''||$body===''||preg_match('/[\r\n]/',$recipient.$subject))throw new RuntimeException('Preencha um email válido, assunto e mensagem.');
        $find=$pdo->prepare('SELECT id,purchase_order_id FROM erp_purchase_order_emails WHERE dispatch_key=?');
        $find->execute([$key]);$existing=$find->fetch(PDO::FETCH_ASSOC);
        if($existing){if((int)$existing['purchase_order_id']!==$id)throw new RuntimeException('Pedido de envio inválido.');return (int)$existing['id'];}
        $pdo->beginTransaction();
        try {
            $snapshot=PurchaseOrderDocument::snapshot($pdo,$id);
            if(in_array($snapshot['order']['status'],['Cancelada','Rascunho'],true))throw new RuntimeException('Abra e guarde a encomenda antes de enviar ao fornecedor.');
            $pdf=PurchaseOrderDocument::pdf($snapshot);
            $q=$pdo->prepare('INSERT INTO erp_purchase_order_emails(purchase_order_id,recipient,subject,body,snapshot_json,pdf_content,pdf_sha256,dispatch_key,status,created_by) VALUES(?,?,?,?,?,?,?,?,?,?)');
            $values=[$id,$recipient,$subject,$body,json_encode($snapshot,JSON_UNESCAPED_UNICODE),$pdf,hash('sha256',$pdf),$key,'Em envio',$userId];
            foreach($values as $index=>$value)$q->bindValue($index+1,$value,$index===5?PDO::PARAM_LOB:(is_int($value)?PDO::PARAM_INT:PDO::PARAM_STR));
            $q->execute();
            $emailId=(int)$pdo->lastInsertId();$pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        $sent=false;$error='';
        try {
            $result=$transport($recipient,$subject,$body,[['name'=>'Encomenda-'.preg_replace('/[^A-Za-z0-9_.-]/','_',$snapshot['order']['order_number']).'.pdf','mime'=>'application/pdf','content'=>$pdf]]);
            $sent=!empty($result['sent']);$error=$sent?'':(string)($result['error']??'O transporte não confirmou o envio.');
        }catch(Throwable $e){$error=$e->getMessage();}
        $pdo->prepare('UPDATE erp_purchase_order_emails SET status=?,error_message=? WHERE id=?')->execute([$sent?'Enviado':'Falhou',$error,$emailId]);
        return $emailId;
    }
}
