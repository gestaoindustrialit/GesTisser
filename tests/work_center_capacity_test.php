<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Services/WorkCenterCapacityService.php';
require_once __DIR__ . '/../erp_migrations.php';

if ((new ReflectionFunction('erp_migrate_work_centers'))->hasReturnType()) {
    throw new RuntimeException('A migração deve manter compatibilidade com PHP 7.0 e não declarar retorno void.');
}

function assertSameValue($expected, $actual, string $message)
{
    if ($expected !== $actual) throw new RuntimeException($message . ': esperado ' . var_export($expected, true) . ', obtido ' . var_export($actual, true));
}

assertSameValue(360.0, WorkCenterCapacityService::netDailyMinutes(['daily_capacity_minutes'=>480,'efficiency_percent'=>75]), 'Capacidade líquida incorreta');
assertSameValue(null, WorkCenterCapacityService::forecastDate(60, 0), 'Centro sem capacidade não pode produzir previsão');
$friday = new DateTimeImmutable('2026-09-18');
assertSameValue('2026-09-21', WorkCenterCapacityService::forecastDate(360, 360, $friday)->format('Y-m-d'), 'A previsão deve ignorar o fim de semana');
assertSameValue('2026-09-22', WorkCenterCapacityService::forecastDate(361, 360, $friday)->format('Y-m-d'), 'A fila deve arredondar para dias completos');

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE erp_machines (id INTEGER PRIMARY KEY); CREATE TABLE erp_work_centers (id INTEGER PRIMARY KEY, code TEXT, name TEXT)');
erp_migrate_work_centers($pdo);
$columns = array_column($pdo->query('PRAGMA table_info(erp_work_centers)')->fetchAll(PDO::FETCH_ASSOC), 'name');
foreach (['center_type','machine_id','default_printer_id','daily_capacity_minutes','efficiency_percent'] as $column) {
    if (!in_array($column, $columns, true)) throw new RuntimeException('A migração não criou ' . $column);
}
$pdo->exec("INSERT INTO erp_printers(name,network_uri) VALUES ('Etiquetas','ipp://printer/ipp/print')");
assertSameValue('ipp://printer/ipp/print', $pdo->query('SELECT network_uri FROM erp_printers')->fetchColumn(), 'Impressora de rede não persistida');

echo "Cálculo da capacidade dos centros de trabalho validado.\n";
