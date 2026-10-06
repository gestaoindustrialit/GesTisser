<?php
declare(strict_types=1);

final class ProductLabelService
{
    private $pdo;
    public function __construct(PDO $pdo) { $this->pdo=$pdo; }

    /** Loads every immutable label value from server-side ERP records. */
    public function data(int $orderId,int $orderOperationId): array
    {
        $sql='SELECT o.id production_order_id,o.order_number,o.planned_quantity,COALESCE(NULLIF(json_extract(pos.snapshot_json,"$._order.lot"),""),NULLIF(json_extract(ts.snapshot_json,"$._order.lot"),""),o.order_number) lot,o.delivery_address_snapshot,
            opo.id operation_id,opo.operation_id operation_type_id,op.product_label_enabled,
            c.name customer_name,c.postal_code customer_postal_code,c.city customer_city,
            da.postal_code delivery_postal_code,da.city delivery_city,
            COALESCE(fp.code,p.code) product_code,COALESCE(fp.description,p.description) product_description,
            COALESCE((SELECT SUM(te.quantity_good) FROM erp_operation_time_entries te WHERE te.production_order_operation_id=opo.id),0) produced_quantity
            FROM erp_production_order_operations opo
            JOIN erp_production_orders o ON o.id=opo.production_order_id
            JOIN erp_operations op ON op.id=opo.operation_id
            LEFT JOIN erp_customers c ON c.id=o.customer_id
            LEFT JOIN erp_customer_delivery_addresses da ON da.id=o.delivery_address_id
            LEFT JOIN erp_finished_products fp ON fp.id=o.finished_product_id
            LEFT JOIN erp_products p ON p.id=o.product_id
            LEFT JOIN erp_production_order_snapshots pos ON pos.production_order_id=o.id
            LEFT JOIN erp_technical_sheets ts ON ts.production_order_id=o.id
            WHERE o.id=? AND opo.id=? LIMIT 1';
        $stmt=$this->pdo->prepare($sql);$stmt->execute([$orderId,$orderOperationId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new RuntimeException('A operação não pertence à Ordem de Fabrico indicada.');
        if((int)$row['product_label_enabled']!==1)throw new RuntimeException('Esta operação não permite imprimir etiquetas de produto.');
        $snapshot=json_decode((string)($row['delivery_address_snapshot']??''),true)?:[];
        $first=function(array $values):string{foreach($values as$value){$value=trim((string)$value);if($value!=='')return $value;}return '';};
        $postal=$first([$snapshot['postal_code']??'',$row['delivery_postal_code']??'',$row['customer_postal_code']??'']);
        $city=$first([$snapshot['city']??'',$row['delivery_city']??'',$row['customer_city']??'']);
        $row['locality']=trim($postal.' '.$city);
        $row['default_quantity']=(float)$row['produced_quantity']>0?(float)$row['produced_quantity']:(float)$row['planned_quantity'];
        return $row;
    }

    public function validatePrint(array $input): array
    {
        $quantity=(float)str_replace(',','.',trim((string)($input['quantity']??'')));
        $copies=filter_var($input['copies']??null,FILTER_VALIDATE_INT);
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',trim((string)($input['label_date']??'')));
        if($quantity<=0)throw new InvalidArgumentException('A quantidade deve ser superior a zero.');
        if($copies===false||$copies<1||$copies>99)throw new InvalidArgumentException('O número de etiquetas deve estar entre 1 e 99.');
        if(!$date||$date->format('Y-m-d')!==(string)($input['label_date']??''))throw new InvalidArgumentException('A data da etiqueta é inválida.');
        return ['quantity'=>$quantity,'copies'=>(int)$copies,'date'=>$date];
    }

    public function audit(array $data,array $values,int $userId): void
    {
        $details=['operation_id'=>(int)$data['operation_id'],'operation_type_id'=>(int)$data['operation_type_id'],'lot_number'=>(string)$data['lot'],'quantity'=>$values['quantity'],'copies'=>$values['copies']];
        $this->pdo->prepare('INSERT INTO erp_production_order_audit(production_order_id,user_id,action,new_value_json) VALUES (?,? ,"production_label_printed",?)')->execute([(int)$data['production_order_id'],$userId,json_encode($details,JSON_UNESCAPED_UNICODE)]);
    }
}
