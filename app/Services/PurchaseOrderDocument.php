<?php
/** Purchase documents are immutable snapshots; delivery uses the configured ERP mail transport. */
final class PurchaseOrderDocument
{
    public static function lines(PDO $pdo, int $id): array
    {
        $q=$pdo->prepare('SELECT pol.*, CASE WHEN pol.item_type="raw_material" THEN rm.code ELSE fp.code END item_code, COALESCE(NULLIF(pol.description,""),CASE WHEN pol.item_type="raw_material" THEN rm.description ELSE fp.description END) item_description, COALESCE(NULLIF(pol.unit_normalized,""),u.code,fu.code,"") unit_code, COALESCE(NULLIF(rm.ink_color,""),c.name) color_name, COALESCE(NULLIF(rm.ink_pantone,""),c.pantone) pantone, rm.legacy_ink_id, rm.is_water_based_ink, COALESCE((SELECT SUM(sm.quantity) FROM erp_stock_movements sm WHERE sm.purchase_order_line_id=pol.id AND sm.movement_type="Entrada"),0) received_quantity FROM erp_purchase_order_lines pol LEFT JOIN erp_raw_materials rm ON pol.item_type="raw_material" AND rm.id=pol.item_id LEFT JOIN erp_finished_products fp ON pol.item_type="finished_product" AND fp.id=pol.item_id LEFT JOIN erp_units u ON u.id=rm.primary_unit_id LEFT JOIN erp_units fu ON fu.id=fp.unit_id LEFT JOIN erp_colors c ON c.id=rm.color_id WHERE pol.purchase_order_id=? ORDER BY pol.line_number,pol.id');
        $q->execute([$id]);return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    // Equal weight per line avoids adding quantities expressed in different units.
    public static function progress(array $lines): float
    {
        if(!$lines)return 0.0;$sum=0.0;foreach($lines as $line){$qty=(float)$line['quantity'];$sum+=$qty>0?min(1,max(0,(float)$line['received_quantity']/$qty)):0;}return round(100*$sum/count($lines),1);
    }
    public static function snapshot(PDO $pdo,int $id): array
    {
        $q=$pdo->prepare('SELECT po.*,s.name supplier_name,s.address,s.address_2,s.postal_code,s.country,s.email FROM erp_purchase_orders po JOIN erp_suppliers s ON s.id=po.supplier_id WHERE po.id=?');$q->execute([$id]);$order=$q->fetch(PDO::FETCH_ASSOC);
        if(!$order)throw new OutOfBoundsException('Encomenda não encontrada.');
        $lines=self::lines($pdo,$id);if(!$lines)throw new RuntimeException('A encomenda não tem artigos.');
        $company=[];foreach(['name','address','email','phone','tax_number','fax'] as $key)$company[$key]=(string)app_setting($pdo,'company_'.$key,$key==='name'?'TISSER':'');
        require_once __DIR__.'/SalesOrderConfirmation.php';
        $logo=trim((string)app_setting($pdo,'logo_report_dark',''));if($logo==='')$logo=(string)app_setting($pdo,'logo_navbar_light','');
        $company['logo_data']=SalesOrderConfirmation::logoData($logo,dirname(__DIR__,2));
        return ['order'=>$order,'lines'=>$lines,'company'=>$company];
    }
    public static function pdf(array $snapshot): string
    {
        require_once __DIR__.'/PurchaseOrderPdf.php';
        return PurchaseOrderPdf::render($snapshot);
    }
}
