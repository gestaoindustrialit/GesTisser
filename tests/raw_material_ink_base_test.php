<?php
declare(strict_types=1);

$erp = (string) file_get_contents(__DIR__ . '/../erp.php');
$migrations = (string) file_get_contents(__DIR__ . '/../erp_migrations.php');
$settings = (string) file_get_contents(__DIR__ . '/../erp_settings.php');

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

echo "raw_material_ink_base_test: OK\n";
