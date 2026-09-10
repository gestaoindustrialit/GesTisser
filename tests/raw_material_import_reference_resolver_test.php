<?php
require_once __DIR__ . '/../app/Services/RawMaterialImportReferenceResolver.php';

function assert_reference($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE erp_material_types (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT NOT NULL UNIQUE, name TEXT NOT NULL, is_active INTEGER NOT NULL DEFAULT 1)');
$pdo->exec('CREATE TABLE erp_material_features (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT NOT NULL UNIQUE, description TEXT NOT NULL, is_active INTEGER NOT NULL DEFAULT 1)');
$pdo->exec("INSERT INTO erp_material_types(code,name,is_active) VALUES (' RF ','Ráfia',0)");
$pdo->exec("INSERT INTO erp_material_features(code,description,is_active) VALUES ('LAM','Laminado',1)");

$preview = new RawMaterialImportReferenceResolver($pdo, true);
$type = $preview->resolveType('  rf  ');
$feature = $preview->resolveFeature(' Nova ');
$duplicatePreview = $preview->resolveFeature('nova');
assert_reference($type['id'] === 1 && !$type['create'], 'O tipo existente deve ser encontrado sem distinguir maiúsculas ou espaços laterais.');
assert_reference($feature['id'] === null && $feature['create'] && $feature['value'] === 'Nova', 'A pré-visualização deve anunciar a nova característica sem a inserir.');
assert_reference(!$duplicatePreview['create'], 'A mesma referência planeada não deve ser anunciada nem criada duas vezes.');
assert_reference((int) $pdo->query('SELECT COUNT(*) FROM erp_material_features')->fetchColumn() === 1, 'A pré-visualização não pode alterar a base de dados.');

$import = new RawMaterialImportReferenceResolver($pdo, false);
$createdType = $import->resolveType('PE');
$createdFeature = $import->resolveFeature(' Nova ');
$reusedFeature = $import->resolveFeature('NOVA');
assert_reference($createdType['id'] > 0 && $createdType['create'], 'O tipo inexistente deve ser criado.');
assert_reference($createdFeature['id'] > 0 && $createdFeature['create'], 'A característica inexistente deve ser criada.');
assert_reference($reusedFeature['id'] === $createdFeature['id'] && !$reusedFeature['create'], 'A característica criada deve ser reutilizada sem duplicação.');
$typeRow = $pdo->query("SELECT code,name,is_active FROM erp_material_types WHERE code='PE'")->fetch(PDO::FETCH_ASSOC);
$featureRow = $pdo->query("SELECT code,description,is_active FROM erp_material_features WHERE code='Nova'")->fetch(PDO::FETCH_ASSOC);
assert_reference($typeRow === ['code' => 'PE', 'name' => 'PE', 'is_active' => 1], 'O tipo deve usar o valor no código e nome e ficar ativo.');
assert_reference($featureRow === ['code' => 'Nova', 'description' => 'Nova', 'is_active' => 1], 'A característica deve usar o valor no código e descrição e ficar ativa.');

echo "Resolução de tipos e características da importação validada.\n";
