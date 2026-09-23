<?php
declare(strict_types=1);
require_once __DIR__.'/../app/Services/ProductionDossierService.php';

$pdo=new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE erp_production_cost_rates(cost_type TEXT,reference_id INTEGER,hourly_rate REAL,is_active INTEGER,valid_from TEXT,valid_until TEXT)');
$pdo->exec('INSERT INTO erp_production_cost_rates VALUES ("machine",NULL,30,1,"2020-01-01",NULL),("machine",7,60,1,"2020-01-01",NULL),("operator",NULL,20,1,"2020-01-01",NULL)');
$service=new ProductionDossierService($pdo);
$method=new ReflectionMethod(ProductionDossierService::class,'calculateCosts');
$method->setAccessible(true);
$base=['planned_minutes'=>60,'actual_minutes'=>30,'operators_count'=>2,'selected_machine_id'=>null];
$labourOnly=$method->invoke($service,['planned_quantity'=>1],[],[array_merge($base,['primary_machine_id'=>null])],[]);
if(isset(array_column($labourOnly['rows'],null,'category')['Máquina']))throw new RuntimeException('Uma operação sem máquina recebeu custo de máquina.');
if($labourOnly['planned_total']!==40.0||$labourOnly['actual_total']!==20.0)throw new RuntimeException('O custo exclusivo de mão de obra está incorreto.');
$withMachine=$method->invoke($service,['planned_quantity'=>1],[],[array_merge($base,['primary_machine_id'=>7])],[]);
$rows=array_column($withMachine['rows'],null,'category');
if((float)$rows['Máquina']['planned']!==60.0||(float)$withMachine['planned_total']!==100.0)throw new RuntimeException('O custo de máquina selecionada não foi aplicado.');
echo "production_dossier_cost_test: OK\n";
