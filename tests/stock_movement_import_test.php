<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/Services/StockMovementSpreadsheet.php';
require_once dirname(__DIR__).'/app/Services/StockMovementImportService.php';

function stock_assert($condition,string $message):void{if(!$condition)throw new RuntimeException($message);}

$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE erp_warehouses(id INTEGER PRIMARY KEY AUTOINCREMENT,code TEXT,name TEXT,location TEXT,is_active INTEGER);CREATE TABLE erp_locations(id INTEGER PRIMARY KEY AUTOINCREMENT,warehouse_id INTEGER,code TEXT,description TEXT,is_active INTEGER);CREATE TABLE erp_raw_materials(id INTEGER PRIMARY KEY AUTOINCREMENT,code TEXT,product_category TEXT,standard_warehouse_id INTEGER);CREATE TABLE erp_settings(key TEXT PRIMARY KEY,value TEXT);CREATE TABLE erp_stock_balances(item_type TEXT,item_id INTEGER,warehouse_id INTEGER,location_id INTEGER,lot TEXT,physical_qty REAL,reserved_qty REAL DEFAULT 0,blocked_qty REAL DEFAULT 0,ordered_qty REAL DEFAULT 0,updated_at TEXT);CREATE TABLE erp_stock_movements(id INTEGER PRIMARY KEY AUTOINCREMENT,movement_number TEXT UNIQUE,movement_date TEXT,movement_type TEXT,item_type TEXT,item_id INTEGER,lot TEXT,quantity REAL,warehouse_from_id INTEGER,location_from_id INTEGER,warehouse_to_id INTEGER,location_to_id INTEGER,unit_cost REAL,total_cost REAL,source_type TEXT,reason TEXT,notes TEXT,created_by INTEGER);INSERT INTO erp_settings VALUES ("allow_negative_stock","0");INSERT INTO erp_warehouses(code,name,location,is_active) VALUES ("ARM","Principal","",1);INSERT INTO erp_locations(warehouse_id,code,description,is_active) VALUES (1,"GERAL","Geral",1);INSERT INTO erp_raw_materials(code,product_category,standard_warehouse_id) VALUES ("MP-01","raw_material",1),("TINTA-01","subsidiary",NULL);');

$rows=[
 ['numero_movimento'=>'LEG-1','data'=>'10/09/2026','codigo_artigo'=>'MP-01','movimento'=>'Entrada','quantidade'=>'10,5','armazem'=>'','localizacao'=>'','lote'=>'A','custo_unitario'=>'2,00'],
 ['numero_movimento'=>'LEG-2','data'=>'10/09/2026','codigo_artigo'=>'TINTA-01','movimento'=>'Entrada','quantidade'=>'4','armazem'=>'','localizacao'=>'','lote'=>'','custo_unitario'=>'3'],
];
$result=StockMovementImportService::import($pdo,$rows,7);
stock_assert($result===['imported'=>2,'skipped'=>0,'general'=>2],'O resumo da importação está incorreto.');
stock_assert((float)$pdo->query("SELECT physical_qty FROM erp_stock_balances b JOIN erp_raw_materials r ON r.id=b.item_id WHERE r.code='MP-01'")->fetchColumn()===10.5,'O stock da matéria-prima não foi atualizado.');
stock_assert((float)$pdo->query("SELECT physical_qty FROM erp_stock_balances b JOIN erp_raw_materials r ON r.id=b.item_id WHERE r.code='TINTA-01'")->fetchColumn()===4.0,'O stock da tinta não foi atualizado.');
stock_assert((string)$pdo->query("SELECT w.code FROM erp_stock_balances b JOIN erp_raw_materials r ON r.id=b.item_id JOIN erp_warehouses w ON w.id=b.warehouse_id WHERE r.code='TINTA-01'")->fetchColumn()==='GERAL','Uma tinta sem armazém standard não foi colocada no GERAL.');
$again=StockMovementImportService::import($pdo,$rows,7);stock_assert($again['imported']===0&&$again['skipped']===2,'Movimentos repetidos não foram ignorados.');

$tmp=tempnam(sys_get_temp_dir(),'stock_csv_');file_put_contents($tmp,"documento;data_movimento;codigo_material;tipo_movimento;qtd\nX-1;10/09/2026;MP-01;Entrada;1\n");try{$parsed=StockMovementSpreadsheet::read($tmp,'csv');}finally{@unlink($tmp);}
stock_assert(($parsed[0]['numero_movimento']??null)==='X-1'&&($parsed[0]['codigo_artigo']??null)==='MP-01','Os aliases da folha de movimentos não foram normalizados.');
echo "Importação de movimentos, stock, GERAL e duplicados validados.\n";
