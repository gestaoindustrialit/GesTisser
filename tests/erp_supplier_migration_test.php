<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/erp_migrations.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE erp_suppliers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    tax_number TEXT,
    country TEXT DEFAULT "Portugal",
    address TEXT,
    phone TEXT,
    email TEXT,
    is_active INTEGER NOT NULL DEFAULT 1
)');
$pdo->exec("INSERT INTO erp_suppliers(code,name) VALUES ('LEGACY','Fornecedor legado')");

erp_migrate_supplier_columns($pdo);

$legacy = $pdo->query("SELECT * FROM erp_suppliers WHERE code='LEGACY'")->fetch(PDO::FETCH_ASSOC);
if (!$legacy || !array_key_exists('address_2', $legacy) || empty($legacy['created_at']) || empty($legacy['updated_at'])) {
    throw new RuntimeException('A migração não atualizou corretamente um fornecedor existente.');
}

$pdo->exec("INSERT INTO erp_suppliers(code,name,address_2) VALUES ('NEW','Fornecedor novo','Piso 2')");
$new = $pdo->query("SELECT * FROM erp_suppliers WHERE code='NEW'")->fetch(PDO::FETCH_ASSOC);
if (!$new || $new['address_2'] !== 'Piso 2' || empty($new['created_at']) || empty($new['updated_at'])) {
    throw new RuntimeException('Os campos ou timestamps do novo fornecedor não foram guardados.');
}

echo "Migração de fornecedores legados validada.\n";
