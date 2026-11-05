<?php
/** Purchase documents are immutable snapshots; delivery uses the configured ERP mail transport. */
final class PurchaseOrderDocument
{
    public static function lines(PDO $pdo, int $id): array
    {
        $q=$pdo->prepare('SELECT pol.*, CASE WHEN pol.item_type="raw_material" THEN rm.code ELSE fp.code END item_code, COALESCE(NULLIF(pol.description,""),CASE WHEN pol.item_type="raw_material" THEN rm.description ELSE fp.description END) item_description, COALESCE(NULLIF(pol.unit_normalized,""),u.code,fu.code,"") unit_code, c.name color_name, c.pantone, COALESCE((SELECT SUM(sm.quantity) FROM erp_stock_movements sm WHERE sm.purchase_order_line_id=pol.id AND sm.movement_type="Entrada"),0) received_quantity FROM erp_purchase_order_lines pol LEFT JOIN erp_raw_materials rm ON pol.item_type="raw_material" AND rm.id=pol.item_id LEFT JOIN erp_finished_products fp ON pol.item_type="finished_product" AND fp.id=pol.item_id LEFT JOIN erp_units u ON u.id=rm.primary_unit_id LEFT JOIN erp_units fu ON fu.id=fp.unit_id LEFT JOIN erp_colors c ON c.id=rm.color_id WHERE pol.purchase_order_id=? ORDER BY pol.line_number,pol.id');
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
        if(!$order)throw new RuntimeException('Encomenda não encontrada.');
        $lines=self::lines($pdo,$id);if(!$lines)throw new RuntimeException('A encomenda não tem artigos.');
        $company=[];foreach(['name','address','email','phone','tax_number','fax'] as $key)$company[$key]=(string)app_setting($pdo,'company_'.$key,$key==='name'?'TISSER':'');
        require_once __DIR__.'/SalesOrderConfirmation.php';
        $company['logo_data']=SalesOrderConfirmation::logoData((string)app_setting($pdo,'logo_report_dark',app_setting($pdo,'logo_navbar_light','')),dirname(__DIR__,2));
        return ['order'=>$order,'lines'=>$lines,'company'=>$company];
    }
    public static function pdf(array $snapshot): string
    {
        require_once dirname(__DIR__,2).'/vendor/autoload.php';
        $e=function($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');};$o=$snapshot['order'];$c=$snapshot['company'];
        $logo=(string)($c['logo_data']??'');$logoHtml=strpos($logo,'data:image/')===0?'<img style="width:50mm" src="'.$e($logo).'">':'';
        $address=trim((string)$o['address'].' '.(string)($o['address_2']??''))."\n".trim((string)($o['postal_code']??'').' '.(string)($o['city']??''))."\n".(string)($o['country']??'');
        $html=$logoHtml.'<style>body{font-family:dejavusans;font-size:10pt}table{border-collapse:collapse;width:100%}th,td{border:1px solid #555;padding:8px}th{background:#eee}h1{font-size:20pt}</style><h1>'.$e($c['name']).'</h1><p>'.nl2br($e($c['address'])).'<br>NIF: '.$e($c['tax_number']).'<br>'.$e($c['email']).' · '.$e($c['phone']).'</p><h2>Encomenda '.$e($o['order_number']).'</h2><p>Data: '.$e($o['order_date']).' · Moeda: EUR<br>Fornecedor: '.$e($o['supplier_name']).'<br>'.nl2br($e(trim($address))).'<br>Entrega prevista: '.$e($o['expected_date']).'<br>Referência fornecedor: '.$e($o['supplier_reference']).'</p><table><thead><tr><th>Quantidade</th><th>Un.</th><th>Preço unit.</th><th>Pantone</th><th>Código / descrição</th><th>Cor</th><th>Total EUR</th></tr></thead><tbody>';$total=0;
        foreach($snapshot['lines'] as $l){$amount=(float)$l['quantity']*(float)$l['unit_price'];$total+=$amount;$html.='<tr><td>'.$e(number_format($l['quantity'],3,',','.')).'</td><td>'.$e($l['unit_code']).'</td><td>'.$e(number_format($l['unit_price'],3,',','.')).'</td><td>'.$e($l['pantone']).'</td><td>'.$e($l['item_code']).'<br>'.$e($l['item_description']).'</td><td>'.$e($l['color_name']).'</td><td>'.$e(number_format($amount,2,',','.')).'</td></tr>';}
        $html.='</tbody></table><p>Total sem IVA: '.$e(number_format($total,2,',','.')).' EUR</p><p>'.nl2br($e($o['notes'])).'</p>';
        $pdf=new \Mpdf\Mpdf(['tempDir'=>sys_get_temp_dir().'/gestisser-purchase-pdf']);$pdf->WriteHTML($html);return $pdf->Output('','S');
    }
}
