<?php
require_once __DIR__.'/../app/Services/SalesOrderConfirmation.php';
$root=sys_get_temp_dir().'/confirmation-logo-'.bin2hex(random_bytes(5));mkdir($root.'/assets/uploads',0750,true);
$bytes=file_get_contents(__DIR__.'/../docs/mapper-reference/ui/assets/logo-tisser-blue.png');file_put_contents($root.'/assets/uploads/report.png',$bytes);
try{
    foreach(['assets/uploads/report.png','/gestisser-dev/assets/uploads/report.png','https://old.example.invalid/gestisser/assets/uploads/report.png?v=2',$root.'/assets/uploads/report.png','https://old.example.invalid/branding/report.png'] as $path){
        $data=SalesOrderConfirmation::logoData($path,$root);
        if(base64_decode(substr($data,strpos($data,',')+1))!==$bytes||!ProductionDossierPdf::reportImage($data))throw new RuntimeException('Logo unresolved: '.$path);
    }
    foreach(['assets/uploads/missing.png','../../etc/passwd'] as $path){$failed=false;try{SalesOrderConfirmation::logoData($path,$root);}catch(RuntimeException $e){$failed=true;}if(!$failed)throw new RuntimeException('Invalid path accepted');}
    file_put_contents($root.'/assets/uploads/report.svg','<svg xmlns="http://www.w3.org/2000/svg"></svg>');$failed=false;try{SalesOrderConfirmation::logoData('assets/uploads/report.svg',$root);}catch(RuntimeException $e){$failed=strpos($e->getMessage(),'PNG ou JPG')!==false;}if(!$failed)throw new RuntimeException('SVG silently omitted');
    if(SalesOrderConfirmation::logoData('',$root)!=='')throw new RuntimeException('Empty branding failure');
    echo "PASS: local, installation-prefixed, absolute and migrated URL logos embedded; missing/unsupported logos reported clearly.\n";
}finally{foreach(glob($root.'/assets/uploads/*') as $file)unlink($file);rmdir($root.'/assets/uploads');rmdir($root.'/assets');rmdir($root);}
