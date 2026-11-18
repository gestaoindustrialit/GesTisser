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
        require_once __DIR__.'/ReportLogo.php';
        require_once __DIR__.'/PurchaseOrderPdf.php';
        $company['logo_data']='';$company['logo_error']='';
        $configured=trim((string)app_setting($pdo,'logo_report_dark',''));
        if($configured==='')$configured=trim((string)app_setting($pdo,'logo_navbar_light',''));
        if($configured!==''){
            $logo=ReportLogo::source($configured,dirname(__DIR__,2));
            if(preg_match('#^https?://#i',$logo))$logo=self::remoteLogo($logo);
            if($logo!=='' && PurchaseOrderPdf::logo($logo))$company['logo_data']=$logo;
            else $company['logo_error']='Não foi possível carregar no PDF o logótipo definido em Empresa e Branding ('.(trim((string)app_setting($pdo,'logo_report_dark',''))!==''?'Logo escuro — relatórios':'Logo claro — navbar').'). Verifique o ficheiro configurado; utilize PNG, JPEG ou SVG vetorial compatível.';
        }
        return ['order'=>$order,'lines'=>$lines,'company'=>$company];
    }
    /** Freeze a configured remote logo in the snapshot rather than retaining a mutable URL. */
    private static function remoteLogo(string $url): string
    {
        $context=stream_context_create(['http'=>['timeout'=>8,'follow_location'=>1,'max_redirects'=>3]]);
        $bytes=@file_get_contents($url,false,$context,0,16*1024*1024+1);
        if((!is_string($bytes)||$bytes==='')&&function_exists('curl_init')){
            $curl=curl_init($url);curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>8,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>3]);
            $bytes=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);if($status<200||$status>=300)$bytes=false;
        }
        if(!is_string($bytes)||$bytes===''||strlen($bytes)>16*1024*1024)return '';
        $info=@getimagesizefromstring($bytes);$mime=is_array($info)?(string)($info['mime']??''):'';
        if($mime===''&&preg_match('/<svg\b/i',$bytes))$mime='image/svg+xml';
        return in_array($mime,['image/png','image/jpeg','image/webp','image/svg+xml'],true)?'data:'.$mime.';base64,'.base64_encode($bytes):'';
    }
    public static function pdf(array $snapshot): string
    {
        if(!empty($snapshot['company']['logo_error']))throw new RuntimeException($snapshot['company']['logo_error']);
        require_once __DIR__.'/PurchaseOrderPdf.php';
        return PurchaseOrderPdf::render($snapshot);
    }
}
