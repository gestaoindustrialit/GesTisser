<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/helpers.php';
require_once dirname(__DIR__).'/hr_organization_lib.php';
require_once dirname(__DIR__).'/app/Services/RoutingService.php';
gt_run_org_migrations($pdo);erp_run_phase1_migrations($pdo);
function check(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);echo "OK - $message\n";}
$pdo->beginTransaction();
try{
    $tag='TEST'.bin2hex(random_bytes(3));$uid=(int)($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn()?:0);if(!$uid){$pdo->prepare('INSERT INTO users(name,email,password,is_admin) VALUES (?,?,?,1)')->execute(['Routing Test',strtolower($tag).'@test.local','test']);$uid=(int)$pdo->lastInsertId();}
    $pdo->prepare('INSERT INTO erp_work_centers(code,name) VALUES (?,?)')->execute([$tag,'Teste routing']);$wc=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO erp_machines(code,name,status,is_active) VALUES (?,? ,"operational",1)')->execute([$tag.'M1','Máquina 1']);$m1=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO erp_machines(code,name,status,is_active) VALUES (?,? ,"operational",1)')->execute([$tag.'M2','Máquina 2']);$m2=(int)$pdo->lastInsertId();
    $svc=new RoutingService($pdo);$op=$svc->saveOperation(['code'=>$tag.'OP','name'=>'Operação teste','work_center_id'=>$wc,'setup_minutes'=>10,'time_per_unit'=>2,'min_operators'=>2,'default_machine_id'=>$m1,'is_active'=>1],[$m1,$m2],$uid);
    check((int)$pdo->query('SELECT COUNT(*) FROM erp_operation_machines WHERE operation_id='.$op)->fetchColumn()===2,'associação de várias máquinas');
    $pdo->prepare('INSERT INTO erp_raw_materials(code,description,product_category,status) VALUES (?,? ,"consumable","Ativo")')->execute([$tag.'CON','Consumível teste']);$material=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO erp_stock_balances(item_type,item_id,warehouse_id,location_id,lot,physical_qty) VALUES ("raw_material",?,0,0,"",250)')->execute([$material]);
    $pdo->prepare('INSERT INTO erp_finished_products(code,description,status) VALUES (?,? ,"Ativo")')->execute([$tag.'ART','Artigo teste']);$article=(int)$pdo->lastInsertId();$version=$svc->createVersion($article,$uid);$step=$svc->addStep($version,$op,['primary_machine_id'=>$m1,'calculation_unit'=>'minutes_per_unit','material_id'=>[$material],'material_quantity'=>[0.5],'material_reserve'=>[1]], $uid);
    check((float)$pdo->query('SELECT run_value FROM erp_article_routing_steps WHERE id='.$step)->fetchColumn()===2.0,'predefinições copiadas para a etapa');
    check((int)$pdo->query('SELECT reserve_on_order FROM erp_routing_step_materials WHERE routing_step_id='.$step)->fetchColumn()===1,'consumível e política de reserva associados à operação');
    $svc->activateVersion($version,date('Y-m-d'),$uid);$version2=$svc->createVersion($article,$uid,$version);$pdo->prepare('UPDATE erp_article_routing_steps SET run_value=3 WHERE routing_version_id=?')->execute([$version2]);
    check((float)$pdo->query('SELECT run_value FROM erp_article_routing_steps WHERE routing_version_id='.$version)->fetchColumn()===2.0,'nova versão não altera a anterior');
    check((int)$pdo->query('SELECT COUNT(*) FROM erp_routing_step_materials sm JOIN erp_article_routing_steps s ON s.id=sm.routing_step_id WHERE s.routing_version_id='.$version2)->fetchColumn()===1,'materiais copiados para a nova versão');
    $unit=(int)($pdo->query('SELECT id FROM erp_units LIMIT 1')->fetchColumn());$pdo->prepare('INSERT INTO erp_products(code,description,unit_id) VALUES (?,? ,?)')->execute([$tag.'LEG','Compatibilidade',$unit]);$product=(int)$pdo->lastInsertId();$pdo->prepare('INSERT INTO erp_production_orders(order_number,product_id,finished_product_id,planned_quantity) VALUES (?,?,?,?)')->execute([$tag.'OF',$product,$article,100]);$order=(int)$pdo->lastInsertId();$svc->snapshotForOrder($order,$article,100,$uid);
    check((float)$pdo->query('SELECT total_planned_minutes FROM erp_production_order_routing_snapshots WHERE production_order_id='.$order)->fetchColumn()===210.0,'cálculo de tempo previsto e snapshot da OF');
    check((float)$pdo->query('SELECT reserved_qty FROM erp_stock_balances WHERE item_type="raw_material" AND item_id='.$material.' AND physical_qty=250')->fetchColumn()===50.0,'stock reservado ao criar a OF');
    check((string)$pdo->query('SELECT material_category FROM erp_production_order_material_reservations WHERE production_order_id='.$order)->fetchColumn()==='consumable','necessidade da OF preserva a categoria do produto');
    $pdo->prepare('UPDATE erp_article_routing_steps SET run_value=99 WHERE routing_version_id=?')->execute([$version]);check((float)$pdo->query('SELECT run_value FROM erp_production_order_operations WHERE production_order_id='.$order)->fetchColumn()===2.0,'snapshot da OF permanece imutável');
    $poStep=(int)$pdo->query('SELECT id FROM erp_production_order_operations WHERE production_order_id='.$order)->fetchColumn();$pdo->prepare('INSERT INTO erp_operation_time_entries(production_order_operation_id,user_id,selected_machine_id) VALUES (?,?,?)')->execute([$poStep,$uid,$m2]);$entry=(int)$pdo->lastInsertId();$pdo->prepare('INSERT INTO erp_operation_execution_operators(time_entry_id,user_id) VALUES (?,?)')->execute([$entry,$uid]);$pdo->prepare('INSERT INTO erp_operation_waste(time_entry_id,quantity,reason,created_by) VALUES (?,?,?,?)')->execute([$entry,2,'Afinação',$uid]);check((int)$pdo->query('SELECT COUNT(*) FROM erp_operation_waste WHERE time_entry_id='.$entry)->fetchColumn()===1,'execução, operador e desperdício registados');
    $pdo->rollBack();echo "Routing validation passed.\n";
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();fwrite(STDERR,'FAIL - '.$e->getMessage()."\n");exit(1);}
