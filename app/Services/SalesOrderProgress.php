<?php
/** Display progress by line without adding quantities in different units. */
final class SalesOrderProgress
{
    public static function line(array $line): array
    {
        $quantity=(float)($line['quantity']??0);$result=[];
        foreach(['planned','produced','delivered'] as $field)$result[$field]=$quantity>0?max(0.0,min(100.0,100.0*(float)($line[$field]??0)/$quantity)):0;
        return $result;
    }
    public static function summary(array $lines,array $workOrders=[]): array
    {
        $result=['planned'=>0.0,'produced'=>0.0,'delivered'=>0.0,'line_count'=>count($lines)];
        foreach($lines as $line)foreach(self::line($line) as $field=>$percent)$result[$field]+=$percent;
        if($lines)foreach(['planned','produced','delivered'] as $field)$result[$field]/=count($lines);
        $result['production_state']=!$lines?'Sem artigos':($result['produced']>=100?'Produzida':($result['produced']>0?'Produção parcial':($result['planned']>0?'Planeada':'Sem OF')));
        $active=false;$paused=false;foreach($workOrders as $of){if(in_array($of['status']??'',['Em Produção','Em produção','Em curso'],true))$active=true;if(in_array($of['status']??'',['Em Pausa','Em pausa'],true))$paused=true;}
        if($result['produced']<100&&($active||$paused))$result['production_state']=$active?'Em produção':'Em pausa';
        $result['delivery_state']=!$lines?'Sem artigos':($result['delivered']>=100?'Entregue':($result['delivered']>0?'Entrega parcial':'Sem entregas'));
        return $result;
    }
}
