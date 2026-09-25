<?php
$serviceFile = __DIR__ . '/../app/Services/ProductionLabelService.php';
$serviceSource = (string) file_get_contents($serviceFile);
if (preg_match('/\)\s*:\s*\?(?:array|int|string|float|bool)\b/', $serviceSource, $match)) {
    throw new RuntimeException('ProductionLabelService contém retorno nullable incompatível com PHP 7.0: ' . $match[0]);
}
require_once $serviceFile;

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
$pdo->exec('INSERT INTO users(id,name) VALUES (1,"Operador")');
$pdo->exec('CREATE TABLE erp_production_labels (id INTEGER PRIMARY KEY AUTOINCREMENT, production_order_id INTEGER, label_type TEXT, reference_key TEXT, values_json TEXT, validated_by INTEGER, validated_at DATETIME, updated_at DATETIME, UNIQUE(production_order_id,label_type,reference_key))');
$pdo->exec('CREATE TABLE erp_production_label_history (id INTEGER PRIMARY KEY AUTOINCREMENT, production_label_id INTEGER, values_json TEXT, validated_by INTEGER, validated_at DATETIME)');
$pdo->exec('CREATE TABLE erp_production_order_audit (id INTEGER PRIMARY KEY AUTOINCREMENT, production_order_id INTEGER, user_id INTEGER, action TEXT, new_value_json TEXT)');

$service = new ProductionLabelService($pdo);
$saved = $service->save(10, 'roll', 'R01', ['roll_number'=>'R01','quantity'=>'50'], 1);
if (($saved['values']['quantity'] ?? '') !== '50' || empty($saved['validated_at'])) throw new RuntimeException('A etiqueta de rolo não foi validada.');
$service->save(10, 'ink', 'INK-1', ['ink_code'=>'INK-1','quantity'=>'2'], 1);
if (count($service->listForOrder(10, 'ink')) !== 1) throw new RuntimeException('A etiqueta de tinta não foi guardada.');
if ((int) $pdo->query('SELECT COUNT(*) FROM erp_production_label_history')->fetchColumn() !== 2) throw new RuntimeException('Histórico de etiquetas incompleto.');

echo "production_label_service_test: OK\n";
