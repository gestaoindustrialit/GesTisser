<?php
/** Writes both canonical and legacy required fields without replacing tables or line IDs. */
final class PurchaseOrderLineSchema
{
    public static function prepareInsert(PDO $pdo,array $columns): PDOStatement
    {
        $available=[];foreach($pdo->query('PRAGMA table_info(erp_purchase_order_lines)')->fetchAll(PDO::FETCH_ASSOC) as $column)$available[$column['name']]=true;
        $names=$columns;$values=array_map(function($name){return ':'.$name;},$columns);
        foreach(['line_no'=>'line_number','ordered_quantity'=>'quantity','unit_cost'=>'unit_price'] as $legacy=>$canonical){
            if(isset($available[$legacy])&&in_array($canonical,$columns,true)){$names[]=$legacy;$values[]=':'.$canonical;}
        }
        return $pdo->prepare('INSERT INTO erp_purchase_order_lines('.implode(',',$names).') VALUES('.implode(',',$values).')');
    }
}
