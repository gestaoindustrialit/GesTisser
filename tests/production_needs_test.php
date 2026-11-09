<?php
require_once __DIR__.'/../app/Services/ProductionNeeds.php';
function needs_check($ok,$message){if(!$ok)throw new RuntimeException($message);}
$m=['raw_material_id'=>1,'code'=>'RF1','description'=>'Ráfia branca','material_type_code'=>'RF'];
$b=['order'=>['order_number'=>'OF1','status'=>'Em Produção','planned_quantity'=>1000,'produced_quantity'=>0],'snapshot'=>['length'=>70,'front_colors'=>"Azul\nPreto",'back_colors'=>'Azul'],'materials'=>[$m,$m]];
$p=$b;$p['order']['produced_quantity']=500;$p['order']['status']='Em Pausa';
$c=$b;$c['order']['status']='Encerrada';
$e=$b;$e['order']['produced_quantity']=1200;
$n=$b;$n['snapshot']['length']=null;
$r=ProductionNeeds::summarize([$b,$p,$c,$e,$n]);
needs_check(abs($r['raffia']['RF1']['metres']-1092)<0.00001,'Conversion, outstanding quantity, waste or duplicate materials incorrect');
needs_check($r['planned']===4000.0&&$r['remaining']===2500.0,'Closed orders or overproduction incorrect');
needs_check(count($r['colors'])===2,'Colors must be unique');
needs_check(count($r['missing'])===1,'Missing length must mark incomplete forecast');
$b['snapshot']['of_front_colors']='Vermelho';$b['snapshot']['back_colors']='';
$r=ProductionNeeds::summarize([$b]);
needs_check(array_values($r['colors'])===['Vermelho'],'Order colors must override technical colors');
needs_check(ProductionNeeds::summarize([])['remaining']===0.0,'Empty plan');
echo "production_needs_test: OK\n";
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE erp_production_order_snapshots(production_order_id INTEGER,snapshot_json TEXT); CREATE TABLE erp_technical_sheets(production_order_id INTEGER,snapshot_json TEXT); CREATE TABLE erp_raw_materials(id INTEGER,code TEXT,description TEXT,material_type_id INTEGER); CREATE TABLE erp_material_types(id INTEGER,code TEXT); CREATE TABLE erp_production_order_material_reservations(production_order_id INTEGER,material_id INTEGER); INSERT INTO erp_material_types VALUES(1,"RF"); INSERT INTO erp_raw_materials VALUES(1,"RF1","Ráfia",1); INSERT INTO erp_production_order_material_reservations VALUES(1,1),(1,1);');
$q=$pdo->prepare('INSERT INTO erp_technical_sheets VALUES(?,?)');$q->execute([1,json_encode(['length'=>90])]);
$q=$pdo->prepare('INSERT INTO erp_production_order_snapshots VALUES(?,?)');$q->execute([1,json_encode(['length'=>70])]);
$order=$b['order'];$order['id']=1;
$pdo->exec('PRAGMA query_only=ON');
$r=ProductionNeeds::load($pdo,[$order]);
needs_check(abs($r['raffia']['RF1']['metres']-728)<0.00001,'Saved snapshot must override legacy and routing materials must be deduplicated');
echo "production_needs_load: OK\n";
