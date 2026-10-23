<?php
$erp = (string) file_get_contents(__DIR__ . '/../erp.php');
$service = (string) file_get_contents(__DIR__ . '/../production_dossier_service.php');

function production_order_version_check($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

production_order_version_check(strpos($erp, 'name="routing_version_id"') !== false, 'O formulário não permite escolher o routing salvo.');
production_order_version_check(strpos($erp, 'name="technical_sheet_version_id"') === false, 'A ficha técnica deve ser selecionada automaticamente ao criar a OF.');
production_order_version_check(strpos($service, 'status="approved"') !== false && strpos($service, 'effective_from<=date("now")') !== false && strpos($service, 'ORDER BY version_no DESC LIMIT 1') !== false, 'A ficha técnica automática deve usar a última versão aprovada e vigente.');
production_order_version_check(strpos($erp, 'name="planned_pallets"') === false, 'A quantidade prevista de paletes ainda pode ser introduzida manualmente.');
production_order_version_check(strpos($erp, "ceil(\$quantity/\$quantityPerPallet)") !== false, 'A quantidade de paletes não é calculada no servidor.');
production_order_version_check(strpos($erp, 'Math.ceil(quantity/perPallet)') !== false, 'A pré-visualização da quantidade de paletes não é automática.');
production_order_version_check(strpos($service, 'WHERE id=? AND finished_product_id=?') !== false, 'O serviço não valida a versão selecionada contra o artigo.');

// Exercise the actual automatic snapshot choice, not only the form markup.
require_once __DIR__ . '/../production_dossier_service.php';
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE erp_customers(id INTEGER PRIMARY KEY,code TEXT,name TEXT);
CREATE TABLE erp_finished_products(id INTEGER PRIMARY KEY,customer_id INTEGER,code TEXT,description TEXT);
CREATE TABLE erp_article_technical_sheet_versions(id INTEGER PRIMARY KEY,finished_product_id INTEGER,version_no INTEGER,status TEXT,effective_from TEXT,snapshot_json TEXT);
CREATE TABLE erp_production_orders(id INTEGER PRIMARY KEY,technical_sheet_version_id INTEGER,snapshot_id INTEGER,public_token TEXT);
CREATE TABLE erp_production_order_snapshots(id INTEGER PRIMARY KEY,production_order_id INTEGER UNIQUE,technical_sheet_version_id INTEGER,snapshot_json TEXT,created_by INTEGER);');
$pdo->exec("INSERT INTO erp_finished_products VALUES(1,NULL,'ART-1','Artigo');
INSERT INTO erp_production_orders(id) VALUES(10);
INSERT INTO erp_article_technical_sheet_versions VALUES(1,1,1,'approved','2000-01-01','{}'),(2,1,2,'draft','2000-01-01','{}'),(3,1,3,'approved','2099-01-01','{}'),(4,2,4,'approved','2000-01-01','{}');");
$snapshots = new ProductionDossierService($pdo);
$snapshots->createSnapshot(10,1,1);
production_order_version_check((int)$pdo->query('SELECT technical_sheet_version_id FROM erp_production_orders WHERE id=10')->fetchColumn() === 1, 'A seleção automática aceitou uma versão em rascunho, futura ou de outro artigo.');
try {
    $snapshots->createSnapshot(10,1,1,[],4);
    throw new LogicException('Foi aceite uma versão de outro artigo.');
} catch (RuntimeException $expected) {
    production_order_version_check(!($expected instanceof LogicException), 'Foi aceite uma versão de outro artigo.');
}
echo "production_order_version_selection_test: OK\n";
