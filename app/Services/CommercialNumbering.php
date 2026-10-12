<?php
require_once __DIR__.'/NumberSequenceService.php';
/** Caller owns authorization and transaction; only unused commercial numbers may be set. */
final class CommercialNumbering
{
    public static function configure(PDO $pdo,string $code,int $next): array
    {
        if(!$pdo->inTransaction())throw new RuntimeException('A alteração da numeração deve ser transacional.');
        if(!in_array($code,['sales_order','sales_order_confirmation'],true)||$next<1||$next>=PHP_INT_MAX)throw new InvalidArgumentException('Indique um número válido.');
        $pdo->prepare('INSERT OR IGNORE INTO erp_number_sequences(code,prefix,next_number,padding) VALUES(?,?,1,5)')->execute([$code,$code==='sales_order'?'EC-':'CONF-']);
        $stmt=$pdo->prepare('SELECT * FROM erp_number_sequences WHERE code=?');$stmt->execute([$code]);$sequence=$stmt->fetch(PDO::FETCH_ASSOC);
        $maximum=0;$pattern='/^'.preg_quote((string)$sequence['prefix'],'/').'([0-9]+)'.preg_quote((string)($sequence['suffix']??''),'/').'$/D';
        if($code==='sales_order'){
            $exists=$pdo->query('SELECT 1 FROM sqlite_master WHERE type="table" AND name="erp_sales_orders"')->fetchColumn();
            $numbers=$exists?$pdo->query('SELECT order_number FROM erp_sales_orders')->fetchAll(PDO::FETCH_COLUMN):[];
        }else{
            $stmt=$pdo->prepare('SELECT version FROM erp_product_documents WHERE entity_type="sales_order" AND document_type=?');$stmt->execute(['Confirmação de encomenda']);$numbers=$stmt->fetchAll(PDO::FETCH_COLUMN);
        }
        foreach($numbers as $number)if(preg_match($pattern,(string)$number,$match))$maximum=max($maximum,(int)$match[1]);
        if($next<=$maximum)throw new InvalidArgumentException('O próximo número deve ser superior ao último já utilizado nesta série ('.$maximum.').');
        $pdo->prepare('UPDATE erp_number_sequences SET next_number=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$next,(int)$sequence['id']]);
        return ['id'=>(int)$sequence['id'],'old'=>(int)$sequence['next_number'],'next'=>$next,'number'=>NumberSequenceService::format(array_merge($sequence,['next_number'=>$next]))];
    }
}
