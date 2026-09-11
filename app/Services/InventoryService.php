<?php
declare(strict_types=1);

/** Read-only inventory projection shared by the WMS screen and CSV export. */
final class InventoryService
{
    public static function filters(array $input): array
    {
        $numberFilter=function($value){
            $value=trim(str_replace(',','.',(string)$value));
            return $value!==''&&is_numeric($value)?(float)$value:null;
        };
        return [
            'q'=>trim((string)($input['inventory_q']??'')),
            'color'=>trim((string)($input['inventory_color']??'')),
            'width'=>$numberFilter($input['inventory_width']??''),
            'grammage'=>$numberFilter($input['inventory_grammage']??''),
            'item_type'=>in_array(($input['inventory_item_type']??''),['raw_material','finished_product'],true)?(string)$input['inventory_item_type']:'',
            'supplier_id'=>max(0,(int)($input['inventory_supplier_id']??0)),
            'material_type_id'=>max(0,(int)($input['inventory_material_type_id']??0)),
            'warehouse_id'=>max(0,(int)($input['inventory_warehouse_id']??0)),
            'stock_state'=>in_array(($input['inventory_stock_state']??''),['available','unavailable','low'],true)?(string)$input['inventory_stock_state']:'',
        ];
    }

    public static function rows(PDO $pdo,array $filters): array
    {
        self::registerSearchNormalizer($pdo);
        $sql='SELECT inventory.*,(physical_qty-reserved_qty-blocked_qty) available_qty,(physical_qty*unit_cost) stock_value FROM ('
            .'SELECT b.item_type,b.item_id,rm.code,rm.description,rm.product_category category,mt.name item_kind,rm.material_type_id,rm.preferred_supplier_id supplier_id,s.code supplier_code,s.name supplier_name,COALESCE(c.code,"")||" "||COALESCE(c.name,"")||" "||COALESCE(c.pantone,"") color,rm.width,rm.grammage,u.code unit_code,b.warehouse_id,w.code warehouse_code,w.name warehouse_name,b.location_id,l.code location_code,b.lot,b.physical_qty,b.reserved_qty,b.blocked_qty,b.ordered_qty,COALESCE(NULLIF(rm.average_price,0),NULLIF(rm.standard_price,0),0) unit_cost,rm.min_stock FROM erp_stock_balances b JOIN erp_raw_materials rm ON b.item_type="raw_material" AND rm.id=b.item_id LEFT JOIN erp_material_types mt ON mt.id=rm.material_type_id LEFT JOIN erp_suppliers s ON s.id=rm.preferred_supplier_id LEFT JOIN erp_colors c ON c.id=rm.color_id LEFT JOIN erp_units u ON u.id=rm.primary_unit_id LEFT JOIN erp_warehouses w ON w.id=b.warehouse_id LEFT JOIN erp_locations l ON l.id=b.location_id '
            .'UNION ALL SELECT b.item_type,b.item_id,fp.code,fp.description,"finished_product" category,pt.name item_kind,fp.material_type_id,NULL supplier_id,NULL supplier_code,NULL supplier_name,COALESCE(fp.bag_color,"")||" "||COALESCE(fp.front_colors,"")||" "||COALESCE(fp.back_colors,"")||" "||COALESCE(fp.thread_color,"") color,fp.width,fp.grammage,u.code unit_code,b.warehouse_id,w.code warehouse_code,w.name warehouse_name,b.location_id,l.code location_code,b.lot,b.physical_qty,b.reserved_qty,b.blocked_qty,b.ordered_qty,COALESCE(fp.standard_cost,0) unit_cost,fp.min_stock FROM erp_stock_balances b JOIN erp_finished_products fp ON b.item_type="finished_product" AND fp.id=b.item_id LEFT JOIN erp_product_types pt ON pt.id=fp.product_type_id LEFT JOIN erp_units u ON u.id=fp.unit_id LEFT JOIN erp_warehouses w ON w.id=b.warehouse_id LEFT JOIN erp_locations l ON l.id=b.location_id) inventory WHERE 1=1';
        $params=[];
        $searchText='COALESCE(inventory.code,"")||" "||COALESCE(inventory.description,"")||" "||COALESCE(inventory.supplier_code,"")||" "||COALESCE(inventory.supplier_name,"")||" "||COALESCE(inventory.lot,"")';
        self::appendWordSearch($sql,$params,$searchText,$filters['q']);
        self::appendWordSearch($sql,$params,'COALESCE(inventory.color,"")',$filters['color']);
        foreach(['width','grammage']as$field){if($filters[$field]!==null){$sql.=' AND inventory.'.$field.'=?';$params[]=$filters[$field];}}
        foreach(['item_type','supplier_id','material_type_id','warehouse_id']as$field){if($filters[$field]!==''&&$filters[$field]!==0){$sql.=' AND inventory.'.$field.'=?';$params[]=$filters[$field];}}
        if($filters['stock_state']==='available')$sql.=' AND (inventory.physical_qty-inventory.reserved_qty-inventory.blocked_qty)>0';
        elseif($filters['stock_state']==='unavailable')$sql.=' AND (inventory.physical_qty-inventory.reserved_qty-inventory.blocked_qty)<=0';
        elseif($filters['stock_state']==='low')$sql.=' AND inventory.min_stock>0 AND (inventory.physical_qty-inventory.reserved_qty-inventory.blocked_qty)<=inventory.min_stock';
        $sql.=' ORDER BY inventory.code,inventory.warehouse_code,inventory.location_code,inventory.lot';$stmt=$pdo->prepare($sql);$stmt->execute($params);return$stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function appendWordSearch(string &$sql,array &$params,string $expression,string $query): void
    {
        $normalized=self::normalizeSearchText($query);
        if($normalized==='')return;
        foreach(array_values(array_unique(explode(' ',$normalized)))as$word){
            $sql.=' AND erp_search_normalize('.$expression.') LIKE ? ESCAPE "\\"';
            $params[]='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$word).'%';
        }
    }

    private static function registerSearchNormalizer(PDO $pdo): void
    {
        if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='sqlite')return;
        $flags=defined('PDO::SQLITE_DETERMINISTIC')?PDO::SQLITE_DETERMINISTIC:0;
        $pdo->sqliteCreateFunction('erp_search_normalize',[self::class,'normalizeSearchText'],1,$flags);
    }

    public static function normalizeSearchText(string $value): string
    {
        if(class_exists('Transliterator')){
            $converted=transliterator_transliterate('NFD; [:Nonspacing Mark:] Remove; NFC',$value);
            if($converted!==false)$value=$converted;
        }elseif(function_exists('iconv')){
            $converted=@iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$value);
            if($converted!==false)$value=$converted;
        }
        $value=function_exists('mb_strtolower')?mb_strtolower($value,'UTF-8'):strtolower($value);
        return trim((string)preg_replace('/[^\p{L}\p{N}]+/u',' ',$value));
    }
}
