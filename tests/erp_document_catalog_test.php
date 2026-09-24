<?php
require_once __DIR__ . '/../erp_migrations.php';

function document_catalog_assert($condition, $message)
{
    if (!$condition) throw new RuntimeException($message);
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY)');
erp_migrate_document_catalog($pdo);
$documents = $pdo->query('SELECT code,document_number FROM erp_document_catalog')->fetchAll(PDO::FETCH_KEY_PAIR);

document_catalog_assert(count($documents) >= 25, 'O catálogo deve abranger todos os documentos gerados conhecidos.');
document_catalog_assert(($documents['technical_sheet'] ?? '') === 'DOC-ERP-001', 'A ficha técnica deve ter número de controlo.');
document_catalog_assert(($documents['production_dossier'] ?? '') === 'DOC-PRD-001', 'O dossier de produção deve ter número de controlo.');
document_catalog_assert(count($documents) === count(array_unique(array_values($documents))), 'Todos os números de documento devem ser únicos.');

$duplicateRejected = false;
try {
    $pdo->exec("UPDATE erp_document_catalog SET document_number='DOC-ERP-001' WHERE code='production_dossier'");
} catch (PDOException $exception) {
    $duplicateRejected = true;
}
document_catalog_assert($duplicateRejected, 'A base de dados deve rejeitar números de controlo repetidos.');

echo "erp document catalog tests passed\n";
