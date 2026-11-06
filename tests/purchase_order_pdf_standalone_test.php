<?php
// Use only the three local renderer files: this fixture has no vendor directory at all.
$root=sys_get_temp_dir().'/purchase-pdf-standalone-'.bin2hex(random_bytes(5));mkdir($root.'/app/Services',0700,true);
$files=['PurchaseOrderDocument.php','PurchaseOrderPdf.php','ProductionDossierPdf.php'];
try{
    foreach($files as $file)copy(__DIR__.'/../app/Services/'.$file,$root.'/app/Services/'.$file);
    require $root.'/app/Services/PurchaseOrderDocument.php';
    $snapshot=['company'=>['name'=>'TISSER','address'=>'Rua de teste','email'=>'fixture@example.invalid','phone'=>'','fax'=>'','tax_number'=>'','logo_data'=>''],'order'=>['order_number'=>'ENC-TEST-1','order_date'=>'2026-11-06','expected_date'=>'2026-11-13','status'=>'Aberta','supplier_name'=>'Fornecedor de teste','address'=>'Morada teste','supplier_reference'=>'REF-1','notes'=>'Observações com acentuação.'],'lines'=>[['quantity'=>40,'unit_code'=>'KG','unit_price'=>6.65,'pantone'=>'072','item_code'=>'5SF-TEST','item_description'=>'Tinta (azul) \\ referência','color_name'=>'AZUL'],['quantity'=>20,'unit_code'=>'KG','unit_price'=>6.49,'pantone'=>'000','item_code'=>'SECOND','item_description'=>'Tinta amarela','color_name'=>'AMARELO']]];
    $pdf=PurchaseOrderDocument::pdf($snapshot);
    if(substr($pdf,0,8)!=='%PDF-1.4'||strpos($pdf,'395,80')===false||strpos($pdf,'072')===false||strpos($pdf,'Fornecedor')===false)throw new RuntimeException('PDF autónomo inválido ou dados incompletos.');
    if(is_dir($root.'/vendor'))throw new RuntimeException('O teste não pode depender de vendor.');
    $snapshot['lines']=array_fill(0,150,$snapshot['lines'][0]);$long=PurchaseOrderDocument::pdf($snapshot);
    if(preg_match_all('#/Type\s*/Page\b#',$long,$matches)<3)throw new RuntimeException('Paginação de encomendas extensas inválida.');
    echo "purchase_order_pdf_standalone_test: OK — PDF sem Composer, fontes externas ou ficheiros temporários.\n";
}finally{foreach($files as $file)@unlink($root.'/app/Services/'.$file);@rmdir($root.'/app/Services');@rmdir($root.'/app');@rmdir($root);}
