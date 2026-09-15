<?php
$source=(string)file_get_contents(__DIR__.'/../app/Services/RoutingService.php');

if(preg_match('/\)\s*:\s*void\b/',$source)){
    throw new RuntimeException('RoutingService contém retorno void incompatível com PHP 7.0.');
}

require_once __DIR__.'/../app/Services/RoutingService.php';
$pdo=new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE erp_routing_step_materials(routing_step_id INTEGER,material_id INTEGER,quantity_per_unit REAL,reserve_on_order INTEGER)');
$service=new RoutingService($pdo);
$method=new ReflectionMethod(RoutingService::class,'saveStepMaterials');
$method->setAccessible(true);
$method->invoke($service,1,['material_id'=>['','',''],'material_quantity'=>['','','']]);

if((int)$pdo->query('SELECT COUNT(*) FROM erp_routing_step_materials')->fetchColumn()!==0){
    throw new RuntimeException('Uma operação sem produtos criou consumos inesperados.');
}

echo "routing_service_php70_compatibility_test: OK\n";
