<?php
require_once __DIR__.'/../../app/Services/ColorimetryService.php';
$db=new PDO('sqlite:'.$argv[1]);$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->exec('PRAGMA busy_timeout=5000');
$service=new ColorimetryService($db,['id'=>1,'is_admin'=>1]);
try{$result=$service->execute('consume',['container_id'=>(int)$argv[2],'quantity'=>'1500','notes'=>'Concorrência real'],bin2hex(random_bytes(16)));echo json_encode($result);}
catch(Throwable $e){if($e->getMessage()!=='Stock insuficiente.')throw $e;echo 'insufficient';}
