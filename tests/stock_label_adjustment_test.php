<?php
require __DIR__.'/operation_consumption_service_test.php';
$pdo->exec('ALTER TABLE erp_stock_movements ADD COLUMN warehouse_to_id INTEGER;ALTER TABLE erp_stock_movements ADD COLUMN location_to_id INTEGER;');
$service->finishUnit(2,100,5,'finish-2');
$before=(int)$pdo->query('SELECT COUNT(*) FROM erp_production_consumptions')->fetchColumn();
$u=$service->adjustUnit('ROLL','1800174',110,1800,100,(float)$service->findUnit('ROLL','1800174')['metres'],'Pesagem',5,'adjust-1');
consumption_assert((float)$u['weight_kg']===110.0,'Acerto positivo falhou.');
$service->adjustUnit('ROLL','1800174',110,1800,100,0,'Pesagem',5,'adjust-1');
consumption_assert((float)$pdo->query('SELECT physical_qty FROM erp_stock_balances')->fetchColumn()===110.0,'Acerto repetido alterou stock.');
$u=$service->adjustUnit('ROLL','1800174',90,1500,110,1800,'Correção',5,'adjust-2');
consumption_assert((float)$pdo->query('SELECT physical_qty FROM erp_stock_balances')->fetchColumn()===90.0,'Acerto negativo falhou.');
$service->adjustUnit('ROLL','1800174',90,1490,90,1500,'Medição',5,'adjust-metres');
consumption_assert((int)$pdo->query('SELECT COUNT(*) FROM erp_production_consumptions')->fetchColumn()===$before,'Acerto criou consumo de produção.');
consumption_assert((int)$pdo->query('SELECT COUNT(*) FROM erp_stock_movements WHERE reason="LABEL_ADJUSTMENT"')->fetchColumn()===3,'Falta tag nos movimentos.');
foreach([[80,1400,100,1490,'stale'],[0,0,90,1490,'reserved'],[-1,0,90,1490,'negative']] as $args){try{$service->adjustUnit('ROLL','1800174',$args[0],$args[1],$args[2],$args[3],'Teste',5,$args[4]);throw new Exception('Acerto inválido aceite');}catch(InvalidArgumentException $e){}catch(RuntimeException $e){}}
consumption_assert((float)$pdo->query('SELECT physical_qty FROM erp_stock_balances')->fetchColumn()===90.0,'Falha não reverteu stock.');
$service->startUnit(30,'ROLL','1800174',5,0,'start-3');
try{$service->adjustUnit('ROLL','1800174',80,1400,90,1490,'Teste',5,'in-use');throw new Exception('Acerto em uso aceite');}catch(InvalidArgumentException $e){}
$pdo->exec('INSERT INTO erp_raw_material_ink_labels VALUES(50,7,"ENT-2","LOTE-A",10,"INK-50","AVAILABLE",1,1,10,CURRENT_TIMESTAMP)');
$ink=$service->adjustUnit('INK','INK-50',12,0,10,0,'Pesagem lata',5,'ink-adjust');
consumption_assert((float)$ink['weight_kg']===12.0,'Acerto de tinta falhou.');
consumption_assert((float)$pdo->query('SELECT physical_qty FROM erp_stock_balances')->fetchColumn()===92.0,'Stock de tinta não foi acertado.');
echo "stock_label_adjustment_test: OK\n";
