<?php
require_once __DIR__.'/../app/Services/SalesOrderConfirmation.php';
$root=sys_get_temp_dir().'/confirmation-logo-'.bin2hex(random_bytes(5));mkdir($root.'/assets/uploads',0750,true);
$bytes=file_get_contents(__DIR__.'/../docs/mapper-reference/ui/assets/logo-tisser-blue.png');file_put_contents($root.'/assets/uploads/report.png',$bytes);
try{
    foreach(['assets/uploads/report.png','/gestisser-dev/assets/uploads/report.png','https://old.example.invalid/gestisser/assets/uploads/report.png?v=2',$root.'/assets/uploads/report.png','https://old.example.invalid/branding/report.png'] as $path){
        $data=SalesOrderConfirmation::logoData($path,$root);
        if(base64_decode(substr($data,strpos($data,',')+1))!==$bytes||!ProductionDossierPdf::reportImage($data))throw new RuntimeException('Logo unresolved: '.$path);
    }
    foreach(['assets/uploads/missing.png','../../etc/passwd'] as $path)if(SalesOrderConfirmation::logoData($path,$root)!=='')throw new RuntimeException('Invalid path accepted');
    file_put_contents($root.'/assets/uploads/report.svg','<svg xmlns="http://www.w3.org/2000/svg"></svg>');
    if(strpos(SalesOrderConfirmation::logoData('assets/uploads/report.svg',$root),'data:image/svg+xml;base64,')!==0)throw new RuntimeException('SVG source differs from existing reports');
    $url='https://example.invalid/remote-logo.png';if(SalesOrderConfirmation::logoData($url,$root)!==$url)throw new RuntimeException('Configured URL not retained');
    $snapshot=['number'=>'CONF-TEST','model'=>'DOC-COM-001','issued_at'=>'2026-10-12','issuer'=>'Teste','currency'=>'EUR','company'=>['name'=>'Tisser','logo_data'=>SalesOrderConfirmation::logoData('/gestisser-dev/assets/uploads/report.png',$root),'terms'=>''],'customer'=>['name'=>'Cliente'],'order'=>['order_number'=>'EC-TEST','order_date'=>'2026-10-12','status'=>'Confirmada'],'lines'=>[]];
    $pdf=SalesOrderConfirmationPdf::render($snapshot);if(strpos($pdf,'/Subtype /Image')===false||strpos($pdf,'/Logo Do')===false)throw new RuntimeException('Resolved logo not embedded in actual confirmation PDF');
    $snapshot['company']['logo_data']=SalesOrderConfirmation::logoData('assets/uploads/missing.png',$root);if(strpos(SalesOrderConfirmationPdf::render($snapshot),'%PDF-1.4')!==0)throw new RuntimeException('Missing logo blocks PDF');
    if(SalesOrderConfirmation::logoData('',$root)!=='')throw new RuntimeException('Empty branding failure');
    echo "PASS: local, installation-prefixed, absolute and migrated URL logos embedded; missing logos do not block issuance; configured URL and SVG sources match existing reports.\n";
}finally{foreach(glob($root.'/assets/uploads/*') as $file)unlink($file);rmdir($root.'/assets/uploads');rmdir($root.'/assets');rmdir($root);}
