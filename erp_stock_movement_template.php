<?php
require_once __DIR__.'/helpers.php';
require_once __DIR__.'/erp_migrations.php';
require_once __DIR__.'/app/Services/StockMovementSpreadsheet.php';
require_once __DIR__.'/app/Services/SimpleXlsx.php';
require_login();erp_run_phase1_migrations($pdo);$user=current_user($pdo)?:[];
if(!erp_user_can($pdo,$user,'erp.stock_adjust')){http_response_code(403);exit('Sem permissão.');}
$path=SimpleXlsx::create([StockMovementSpreadsheet::templateColumns(),['MOV-EXEMPLO-001',date('d/m/Y'),'MP-001','Entrada','100','GERAL','GERAL','L-001','1,25','Inventário inicial','Linha de exemplo — eliminar antes de importar']],'Movimentos de stock');
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');header('Content-Disposition: attachment; filename="modelo_movimentos_stock.xlsx"');header('Content-Length: '.filesize($path));readfile($path);unlink($path);
