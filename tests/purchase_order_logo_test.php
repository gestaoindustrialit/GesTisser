<?php
require_once __DIR__.'/../app/Services/PurchaseOrderDocument.php';
require_once __DIR__.'/../app/Services/ProductionDossierPdf.php';
function logo_check($condition,$message){if(!$condition)throw new RuntimeException($message);}
// Hand-built RGBA PNG also exercises hosts without GD.
$width=100;$height=40;$rows='';
for($y=0;$y<$height;$y++){
    $rows.="\0";
    for($x=0;$x<$width;$x++)$rows.="\0\0\0".chr($x>=30&&$x<70&&$y>=10&&$y<30?255:0);
}
function logo_chunk($type,$data){return pack('N',strlen($data)).$type.$data.pack('N',crc32($type.$data));}
$png="\x89PNG\r\n\x1a\n".logo_chunk('IHDR',pack('NNCCCCC',$width,$height,8,6,0,0,0)).logo_chunk('IDAT',gzcompress($rows)).logo_chunk('IEND','');
$uri='data:image/png;base64,'.base64_encode($png);
$image=ProductionDossierPdf::reportImage($uri);
logo_check($image['width']===100&&$image['height']===40,'Preservar dimensões do logótipo.');
if($image['filter']==='DCTDecode'){
    $decoded=imagecreatefromstring($image['data']);
    logo_check((imagecolorat($decoded,0,0)&255)>245,'Transparência deve ficar branca, não preta.');
    logo_check((imagecolorat($decoded,50,20)&255)<10,'Preservar o desenho preto do logótipo.');
    imagedestroy($decoded);
}
$method=new ReflectionMethod('ProductionDossierPdf','pngForPdf');$method->setAccessible(true);
$plain=$method->invoke(null,$png);$pixels=gzuncompress($plain['data']);
logo_check(substr($pixels,1,3)==="\xff\xff\xff",'Transparência branca também sem GD.');
$snapshot=['company'=>['name'=>'Empresa teste','logo_data'=>$uri],'order'=>['order_number'=>'ENC-LOGO','order_date'=>'2026-11-17','status'=>'Aberta'],'lines'=>[]];
$pdf=PurchaseOrderDocument::pdf($snapshot);
logo_check(strpos($pdf,'/Subtype /Image')!==false&&strpos($pdf,'/Logo Do')!==false,'PDF incorpora a imagem.');
logo_check(preg_match('/q ([\d.]+) 0 0 ([\d.]+) 30 [\d.]+ cm \/Logo Do/',$pdf,$match)===1,'Logótipo desenhado no cabeçalho.');
logo_check(abs((float)$match[1]/(float)$match[2]-2.5)<0.01,'Manter proporções sem distorção.');
echo "purchase_order_logo_test: OK\n";
