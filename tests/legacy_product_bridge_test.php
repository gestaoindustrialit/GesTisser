<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Services/LegacyProductBridge.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE erp_products (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL UNIQUE,
    customer_code TEXT,
    description TEXT NOT NULL,
    customer_id INTEGER,
    product_type_id INTEGER,
    material_type_id INTEGER,
    unit_id INTEGER,
    width REAL,
    length REAL,
    grammage REAL,
    min_stock REAL NOT NULL DEFAULT 0,
    unit_cost REAL NOT NULL DEFAULT 0,
    unit_price REAL NOT NULL DEFAULT 0,
    is_active INTEGER NOT NULL DEFAULT 1
)');

$article = [
    'code' => 'CB81F6909932',
    'customer_product_code' => 'CLIENT-42',
    'description' => 'Saco Tisser com asa',
    'customer_id' => 7,
    'product_type_id' => 2,
    'material_type_id' => 3,
    'unit_id' => 4,
    'width' => 36,
    'length' => 55,
    'grammage' => 60,
    'min_stock' => 10,
    'standard_cost' => 0.31,
    'sale_price' => 0.48,
];

$id = LegacyProductBridge::productIdForFinishedProduct($pdo, $article);
$row = $pdo->query('SELECT * FROM erp_products')->fetch(PDO::FETCH_ASSOC);
if ($id < 1 || $row['code'] !== $article['code'] || $row['description'] !== $article['description']) {
    throw new RuntimeException('O artigo de compatibilidade não foi criado com os dados do produto acabado.');
}
if ((int) $row['customer_id'] !== 7 || (float) $row['unit_cost'] !== 0.31 || (float) $row['unit_price'] !== 0.48) {
    throw new RuntimeException('Os dados relevantes para o Shopfloor não foram copiados.');
}

$sameId = LegacyProductBridge::productIdForFinishedProduct($pdo, array_merge($article, ['description' => 'Descrição alterada']));
if ($sameId !== $id || (int) $pdo->query('SELECT COUNT(*) FROM erp_products')->fetchColumn() !== 1) {
    throw new RuntimeException('Uma segunda OF criou um artigo legado duplicado.');
}

echo "Ponte de compatibilidade de artigos validada.\n";
