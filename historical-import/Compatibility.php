<?php
/** Read-only inspection; does not create missing business structures. PHP 7.0. */
final class HistoricalCompatibility
{
    public static function inspect(PDO $pdo)
    {
        $targets=[
            'encomendas'=>null,'encomendas_linhas'=>null,'entradas_mp'=>null,
            'ofs'=>['erp_production_orders',['order_number','customer_id','product_id','finished_product_id','planned_quantity','produced_quantity','status','created_at','due_date','notes','created_by']],
            'of_operacoes'=>['erp_production_order_operations',['production_order_id','operation_id','sequence_no','planned_minutes','status']],
            'rolos_rafia'=>['erp_raw_material_roll_labels',['raw_material_id','entry_number','supplier_lot','metres','weight_kg','initial_metres','initial_weight_kg','barcode','label_date','validated_by','status','warehouse_id','location_id']],
            'lotes_tintas'=>['erp_raw_material_ink_labels',['raw_material_id','entry_number','supplier_lot','weight_kg','initial_weight_kg','barcode','label_date','validated_by','status','warehouse_id','location_id']],
            'movimentos_historicos'=>['erp_stock_movements',['movement_number','movement_date','movement_type','item_type','item_id','quantity','weight','lot','source_type','reason','notes','created_by']],
        ];
        $result=[];
        foreach($targets as $entity=>$target) {
            if($target===null) { $result[$entity]=['target'=>null,'ready'=>false,'reasons'=>['Não existe destino confirmado para esta entidade; não será criada uma tabela concorrente.']];continue; }
            $columns=$pdo->query('PRAGMA table_info("'.$target[0].'")')->fetchAll(PDO::FETCH_ASSOC);
            $names=array_column($columns,'name');$missing=array_values(array_diff($target[1],$names));$required=[];
            foreach($columns as $column) if($column['notnull'] && !$column['pk'] && $column['dflt_value']===null && !in_array($column['name'],$target[1],true)) $required[]=$column['name'];
            $reasons=[];
            if(!$columns) $reasons[]='Tabela de destino inexistente.';
            if($missing) $reasons[]='Colunas do adaptador em falta: '.implode(', ',$missing);
            if($required) $reasons[]='Colunas obrigatórias sem mapeamento: '.implode(', ',$required);
            // An unknown trigger can update balances or catalogs on INSERT: do not trust it silently.
            $stmt=$pdo->prepare("SELECT name FROM sqlite_master WHERE type='trigger' AND tbl_name=?");$stmt->execute([$target[0]]);
            $triggers=$stmt->fetchAll(PDO::FETCH_COLUMN);
            if($triggers) $reasons[]='Destino tem triggers de escrita que exigem revisão específica: '.implode(', ',$triggers);
            $result[$entity]=['target'=>$target[0],'ready'=>!$reasons,'reasons'=>$reasons];
        }
        return $result;
    }
}
