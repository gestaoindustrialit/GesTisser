<?php
declare(strict_types=1);

/** Read-only inventory projection shared by the WMS screen and CSV export. */
final class InventoryService
{
    public static function filters(array $input): array
    {
        return [
            'q'=>trim((string)($input['inventory_q']??'')),
            'item_type'=>in_array(($input['inventory_item_type']??''),['raw_material','finished_product'],true)?(string)$input['inventory_item_type']:'',
            'supplier_id'=>max(0,(int)($input['inventory_supplier_id']??0)),
            'material_type_id'=>max(0,(int)($input['inventory_material_type_id']??0)),
            'warehouse_id'=>max(0,(int)($input['inventory_warehouse_id']??0)),
            'stock_state'=>in_array(($input['inventory_stock_state']??''),['available','unavailable','low'],true)?(string)$input['inventory_stock_state']:'',
        ];
    }

    public static function rows(PDO $pdo,array $filters): array
    {
        $sql='SELECT inventory.*,(physical_qty-reserved_qty-blocked_qty) available_qty,(physical_qty*unit_cost) stock_value FROM ('
            .'SELECT b.item_type,b.item_id,rm.code,rm.description,rm.product_category category,mt.name item_kind,rm.material_type_id,rm.preferred_supplier_id supplier_id,s.name supplier_name,u.code unit_code,b.warehouse_id,w.code warehouse_code,w.name warehouse_name,b.location_id,l.code location_code,b.lot,b.physical_qty,b.reserved_qty,b.blocked_qty,b.ordered_qty,COALESCE(NULLIF(rm.average_price,0),NULLIF(rm.standard_price,0),0) unit_cost,rm.min_stock FROM erp_stock_balances b JOIN erp_raw_materials rm ON b.item_type="raw_material" AND rm.id=b.item_id LEFT JOIN erp_material_types mt ON mt.id=rm.material_type_id LEFT JOIN erp_suppliers s ON s.id=rm.preferred_supplier_id LEFT JOIN erp_units u ON u.id=rm.primary_unit_id LEFT JOIN erp_warehouses w ON w.id=b.warehouse_id LEFT JOIN erp_locations l ON l.id=b.location_id '
            .'UNION ALL SELECT b.item_type,b.item_id,fp.code,fp.description,"finished_product" category,pt.name item_kind,fp.material_type_id,NULL supplier_id,NULL supplier_name,u.code unit_code,b.warehouse_id,w.code warehouse_code,w.name warehouse_name,b.location_id,l.code location_code,b.lot,b.physical_qty,b.reserved_qty,b.blocked_qty,b.ordered_qty,COALESCE(fp.standard_cost,0) unit_cost,fp.min_stock FROM erp_stock_balances b JOIN erp_finished_products fp ON b.item_type="finished_product" AND fp.id=b.item_id LEFT JOIN erp_product_types pt ON pt.id=fp.product_type_id LEFT JOIN erp_units u ON u.id=fp.unit_id LEFT JOIN erp_warehouses w ON w.id=b.warehouse_id LEFT JOIN erp_locations l ON l.id=b.location_id) inventory WHERE 1=1';
        $params=[];
        if($filters['q']!==''){$sql.=' AND (inventory.code LIKE ? OR inventory.description LIKE ? OR COALESCE(inventory.supplier_name,"") LIKE ? OR COALESCE(inventory.lot,"") LIKE ?)';$needle='%'.$filters['q'].'%';$params=[$needle,$needle,$needle,$needle];}
        foreach(['item_type','supplier_id','material_type_id','warehouse_id']as$field){if($filters[$field]!==''&&$filters[$field]!==0){$sql.=' AND inventory.'.$field.'=?';$params[]=$filters[$field];}}
        if($filters['stock_state']==='available')$sql.=' AND (inventory.physical_qty-inventory.reserved_qty-inventory.blocked_qty)>0';
        elseif($filters['stock_state']==='unavailable')$sql.=' AND (inventory.physical_qty-inventory.reserved_qty-inventory.blocked_qty)<=0';
        elseif($filters['stock_state']==='low')$sql.=' AND inventory.min_stock>0 AND (inventory.physical_qty-inventory.reserved_qty-inventory.blocked_qty)<=inventory.min_stock';
        $sql.=' ORDER BY inventory.code,inventory.warehouse_code,inventory.location_code,inventory.lot';$stmt=$pdo->prepare($sql);$stmt->execute($params);return$stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
