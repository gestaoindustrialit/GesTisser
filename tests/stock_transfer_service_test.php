<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/Services/StockTransferService.php';

function transfer_assert($condition, string $message): void { if (!$condition) throw new RuntimeException($message); }

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE erp_raw_materials(id INTEGER PRIMARY KEY);CREATE TABLE erp_finished_products(id INTEGER PRIMARY KEY);CREATE TABLE erp_warehouses(id INTEGER PRIMARY KEY,is_active INTEGER);CREATE TABLE erp_locations(id INTEGER PRIMARY KEY,warehouse_id INTEGER,is_active INTEGER);CREATE TABLE erp_stock_balances(item_type TEXT,item_id INTEGER,warehouse_id INTEGER,location_id INTEGER,lot TEXT,physical_qty REAL,reserved_qty REAL DEFAULT 0,blocked_qty REAL DEFAULT 0,updated_at TEXT,PRIMARY KEY(item_type,item_id,warehouse_id,location_id,lot));CREATE TABLE erp_stock_movements(id INTEGER PRIMARY KEY AUTOINCREMENT,movement_number TEXT UNIQUE,movement_type TEXT,item_type TEXT,item_id INTEGER,lot TEXT,quantity REAL,warehouse_from_id INTEGER,location_from_id INTEGER,warehouse_to_id INTEGER,location_to_id INTEGER,unit_cost REAL,total_cost REAL,reason TEXT,notes TEXT,created_by INTEGER);');
$pdo->exec('INSERT INTO erp_raw_materials VALUES (1);INSERT INTO erp_warehouses VALUES (1,1),(2,1);INSERT INTO erp_locations VALUES (10,1,1),(20,2,1);INSERT INTO erp_stock_balances VALUES ("raw_material",1,1,10,"LOTE-A",12,2,1,CURRENT_TIMESTAMP);');

$pdo->beginTransaction();
$result = StockTransferService::transfer($pdo, ['item_type'=>'raw_material','item_id'=>1,'quantity'=>5,'lot'=>'LOTE-A','warehouse_from_id'=>1,'location_from_id'=>10,'warehouse_to_id'=>2,'location_to_id'=>20,'unit_cost'=>2.5,'reason'=>'Reposição'], 7);
$pdo->commit();
$source = (float) $pdo->query('SELECT physical_qty FROM erp_stock_balances WHERE warehouse_id=1')->fetchColumn();
$destination = (float) $pdo->query('SELECT physical_qty FROM erp_stock_balances WHERE warehouse_id=2')->fetchColumn();
$movement = $pdo->query('SELECT * FROM erp_stock_movements')->fetch(PDO::FETCH_ASSOC);
transfer_assert($source === 7.0 && $destination === 5.0, 'A transferência não atualizou os stocks de origem e destino.');
transfer_assert($movement['movement_type'] === 'Transferência' && (float) $movement['total_cost'] === 12.5, 'O movimento não foi registado corretamente no ledger.');
transfer_assert($result['movement_number'] === $movement['movement_number'], 'O serviço não devolveu o movimento criado.');

$pdo->beginTransaction();
try {
    StockTransferService::transfer($pdo, ['item_type'=>'raw_material','item_id'=>1,'quantity'=>5,'lot'=>'LOTE-A','warehouse_from_id'=>1,'location_from_id'=>10,'warehouse_to_id'=>2,'location_to_id'=>20], 7);
    throw new RuntimeException('A transferência permitiu consumir stock reservado ou bloqueado.');
} catch (RuntimeException $exception) {
    $pdo->rollBack();
    transfer_assert(strpos($exception->getMessage(), 'Stock disponível insuficiente') !== false, 'Foi devolvido um erro inesperado para stock insuficiente.');
}
transfer_assert((float) $pdo->query('SELECT physical_qty FROM erp_stock_balances WHERE warehouse_id=1')->fetchColumn() === 7.0, 'A transferência inválida alterou o stock.');
echo "Transferências entre armazéns e registo no ledger validados.\n";
