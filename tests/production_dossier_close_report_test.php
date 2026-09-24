<?php
declare(strict_types=1);
require_once __DIR__.'/../app/Services/ProductionDossierService.php';

$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE erp_production_order_close_reports(id INTEGER PRIMARY KEY,production_order_id INTEGER UNIQUE,report_json TEXT,updated_by INTEGER,created_at TEXT DEFAULT CURRENT_TIMESTAMP,updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec('CREATE TABLE erp_production_order_audit(id INTEGER PRIMARY KEY,production_order_id INTEGER,user_id INTEGER,action TEXT,old_value_json TEXT,new_value_json TEXT,reason TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$service=new ProductionDossierService($pdo);
$base=$service->closeReport(9,['good'=>100,'rejected'=>3,'waste_percent'=>2.91],['rows'=>[['category'=>'Matérias-primas','actual'=>41],['category'=>'Máquina','actual'=>12]]]);
if((float)$base['produced_quantity']!==100.0||(float)$base['cost_materia_prima']!==41.0)throw new RuntimeException('O relatório não foi pré-preenchido com dados produtivos.');
// Isola a persistência da consulta integral do dossier, mantendo o contrato real do serviço.
$ref=new ReflectionClass($service);$method=$ref->getMethod('row');
$pdo->exec("INSERT INTO erp_production_order_close_reports(production_order_id,report_json,updated_by) VALUES(9,'".str_replace("'","''",json_encode($base))."',1)");
$stored=$service->closeReport(9,[],[]);if((float)$stored['cost_impressora']!==12.0)throw new RuntimeException('O relatório guardado não foi recuperado.');
$page=(string)file_get_contents(__DIR__.'/../production_dossier.php');$print=(string)file_get_contents(__DIR__.'/../production_dossier_print.php');
foreach(['Relatório administrativo de custos','change_reason','update_close_report'] as $term)if(strpos($page.$print.$service::class,$term)===false&&strpos((string)file_get_contents(__DIR__.'/../app/Services/ProductionDossierService.php'),$term)===false)throw new RuntimeException('Elemento administrativo em falta: '.$term);
if(strpos($print,'Margem bruta')===false||strpos($print,'Composição')===false)throw new RuntimeException('O PDF não contém o relatório de fecho completo.');
echo "production_dossier_close_report_test: OK\n";
