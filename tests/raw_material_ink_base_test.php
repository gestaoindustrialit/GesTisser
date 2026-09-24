<?php
declare(strict_types=1);

$erp = (string) file_get_contents(__DIR__ . '/../erp.php');
$migrations = (string) file_get_contents(__DIR__ . '/../erp_migrations.php');
$settings = (string) file_get_contents(__DIR__ . '/../erp_settings.php');
require_once __DIR__ . '/../erp_migrations.php';

function ink_base_check($condition, string $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

ink_base_check(strpos($migrations, 'CREATE TABLE IF NOT EXISTS erp_ink_types') !== false, 'Tabela configurável de tipos de tinta em falta.');
ink_base_check(strpos($migrations, "['AGUA','Tinta de água','bi-droplet-fill']") !== false, 'Tipo de tinta de água ou ícone predefinido em falta.');
ink_base_check(strpos($migrations, "['SOLVENTE','Tinta de solvente','bi-bucket-fill']") !== false, 'Tipo de tinta solvente ou ícone predefinido em falta.');
ink_base_check(strpos($erp, 'name="ink_type_id"') !== false, 'Seletor do tipo de tinta em falta.');
ink_base_check(strpos($erp, "\$r['ink_type_icon']") !== false, 'Ícone antes da descrição/cor em falta.');
ink_base_check(strpos($settings, "save_ink_type") !== false && strpos($settings, 'Tipos de tinta') !== false, 'Gestão dos tipos de tinta nas configurações em falta.');
ink_base_check(strpos($settings, 'name="icon"') !== false, 'Escolha do ícone do tipo de tinta em falta.');

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE erp_raw_materials (id INTEGER PRIMARY KEY, description TEXT, is_water_based_ink INTEGER NOT NULL DEFAULT 0)');
$pdo->exec("INSERT INTO erp_raw_materials(id,description,is_water_based_ink) VALUES (1,'Azul',1)");
erp_migrate_ink_types($pdo);
ink_base_check(erp_table_exists($pdo, 'erp_ink_types'), 'A migração isolada não criou a tabela de tipos de tinta.');
ink_base_check(erp_column_exists($pdo, 'erp_raw_materials', 'ink_type_id'), 'A migração isolada não adicionou a associação à matéria-prima.');
$migrated = $pdo->query('SELECT it.code FROM erp_raw_materials rm JOIN erp_ink_types it ON it.id=rm.ink_type_id WHERE rm.id=1')->fetchColumn();
ink_base_check($migrated === 'AGUA', 'A classificação legada de tinta de água não foi migrada.');
erp_migrate_ink_types($pdo);
ink_base_check((int)$pdo->query('SELECT COUNT(*) FROM erp_ink_types')->fetchColumn() === 2, 'A migração de tipos de tinta não é idempotente.');

echo "raw_material_ink_base_test: OK\n";
