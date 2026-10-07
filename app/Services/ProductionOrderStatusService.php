<?php
declare(strict_types=1);

/** Keeps an open OF status consistent with the operation clocks recorded by Shopfloor. */
final class ProductionOrderStatusService
{
    /** @param int|null $userId */
    public static function syncAll(PDO $pdo, $userId = null): int
    {
        $orders=$pdo->query('SELECT id,status FROM erp_production_orders WHERE status NOT IN ("Encerrada","Fechada","Cancelada")')->fetchAll(PDO::FETCH_ASSOC);
        $changed=0;
        foreach($orders as$order)$changed+=self::syncOrder($pdo,(int)$order['id'],$userId,(string)$order['status'])?1:0;
        return $changed;
    }

    /**
     * @param int|null $userId
     * @param string|null $currentStatus
     */
    public static function syncOrder(PDO $pdo, int $orderId, $userId = null, $currentStatus = null): bool
    {
        if($currentStatus===null){$stmt=$pdo->prepare('SELECT status FROM erp_production_orders WHERE id=?');$stmt->execute([$orderId]);$currentStatus=$stmt->fetchColumn();if($currentStatus===false)return false;$currentStatus=(string)$currentStatus;}
        if(in_array($currentStatus,['Encerrada','Fechada','Cancelada'],true))return false;

        $stmt=$pdo->prepare('SELECT COUNT(*) operation_count,SUM(CASE WHEN opo.status<>"Concluída" THEN 1 ELSE 0 END) pending_count,COUNT(te.id) time_count,SUM(CASE WHEN te.ended_at IS NULL THEN 1 ELSE 0 END) open_count,SUM(CASE WHEN te.ended_at IS NULL AND te.status<>"paused" THEN 1 ELSE 0 END) running_count FROM erp_production_order_operations opo LEFT JOIN erp_operation_time_entries te ON te.production_order_operation_id=opo.id WHERE opo.production_order_id=?');
        $stmt->execute([$orderId]);$activity=$stmt->fetch(PDO::FETCH_ASSOC)?:[];
        if((int)($activity['time_count']??0)===0)return false;
        // Completing the final operation does not close the manufacturing
        // order. Shopfloor records execution only; validation and closure are
        // explicit ERP responsibilities (ProductionDossierService closes it as
        // "Encerrada"). Keeping it open also allows quantities to be reviewed.
        if((int)($activity['open_count']??0)>0&&(int)($activity['running_count']??0)===0)$status='Em Pausa';
        else $status='Em Produção';
        if($status===$currentStatus)return false;

        $pdo->prepare('UPDATE erp_production_orders SET status=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$status,$orderId]);
        $audit=$pdo->prepare('INSERT INTO erp_production_order_audit(production_order_id,user_id,action,old_value_json,new_value_json,reason) VALUES (?,? ,"automatic_status",?,?,?)');
        $audit->execute([$orderId,$userId,json_encode(['status'=>$currentStatus],JSON_UNESCAPED_UNICODE),json_encode(['status'=>$status],JSON_UNESCAPED_UNICODE),'Estado atualizado automaticamente a partir dos tempos das operações.']);
        return true;
    }
}
