<?php
declare(strict_types=1);

require_once __DIR__ . '/../erp_migrations.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY)');

gt_erp_migrate_production_cost_settings($pdo);
gt_erp_migrate_production_cost_settings($pdo);

$rows = $pdo->query('SELECT cost_key,label,unit,is_required FROM erp_production_cost_settings ORDER BY sort_order')->fetchAll(PDO::FETCH_ASSOC);
if (count($rows) !== 6) throw new RuntimeException('A migração deve criar exatamente os seis custos base e ser idempotente.');
if (array_column($rows, 'cost_key') !== ['caixa','palete','diluente','mao_obra_maquina','mao_obra_colaborador','energia_saco']) throw new RuntimeException('As rubricas base de produção não foram criadas na ordem esperada.');
foreach ($rows as $row) if ((int) $row['is_required'] !== 1) throw new RuntimeException('Uma rubrica base foi criada como removível.');

$settingsPage = file_get_contents(__DIR__ . '/../erp_settings.php');
if ($settingsPage === false) throw new RuntimeException('Não foi possível ler a página de configuração do ERP.');
if (strpos($settingsPage, 'name="labor_hourly_rate"') !== false) throw new RuntimeException('A configuração antiga de mão de obra continua visível fora do bloco de custos de produção.');
if (strpos($settingsPage, "\$_POST['labor_hourly_rate']") !== false) throw new RuntimeException('A configuração geral ainda processa o valor antigo de mão de obra.');

$pdo->exec("UPDATE erp_production_cost_settings SET unit_cost=2.5 WHERE cost_key='caixa'");
gt_erp_migrate_production_cost_settings($pdo);
if ((float) $pdo->query("SELECT unit_cost FROM erp_production_cost_settings WHERE cost_key='caixa'")->fetchColumn() !== 2.5) throw new RuntimeException('A migração substituiu um custo configurado.');

echo "erp_production_cost_settings_test: OK\n";
