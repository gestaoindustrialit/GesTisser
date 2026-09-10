<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/erp_migrations.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE erp_material_types (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL
)');
$pdo->exec("INSERT INTO erp_material_types(code,name) VALUES ('RF','Ráfia')");

erp_migrate_material_type_columns($pdo);

$legacy = $pdo->query("SELECT code,name,is_active FROM erp_material_types WHERE code='RF'")->fetch(PDO::FETCH_ASSOC);
if (!$legacy || (int) $legacy['is_active'] !== 1) {
    throw new RuntimeException('A migração não ativou corretamente um tipo de material existente.');
}

$pdo->exec("INSERT INTO erp_material_types(code,name) VALUES ('PL','Plástico')");
$new = $pdo->query("SELECT is_active FROM erp_material_types WHERE code='PL'")->fetchColumn();
if ((int) $new !== 1) {
    throw new RuntimeException('O valor predefinido de um novo tipo de material não é ativo.');
}

erp_migrate_material_type_columns($pdo);

echo "Migração de tipos de material legados validada.\n";
