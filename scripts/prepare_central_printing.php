<?php
// Safe cPanel terminal entry point; no sessions, broad migrations or network calls.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (PHP_VERSION_ID !== 70033) { fwrite(STDERR, "Use PHP 7.0.33 CLI.\n"); exit(1); }
require_once dirname(__DIR__).'/bootstrap/config.php';
require_once dirname(__DIR__).'/app/Services/CentralPrintingSetup.php';
$arguments = array_slice($argv, 1);
if ($arguments && $arguments !== ['--apply']) { fwrite(STDERR, "Uso: php scripts/prepare_central_printing.php [--apply]\nSem --apply: apenas diagnóstico.\n"); exit(1); }
$path = app_config('db_path');
if (!is_file($path)) { fwrite(STDERR, "Base existente indisponível; não foi criada uma base nova.\n"); exit(1); }
try {
    $db = new PDO('sqlite:'.$path);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA foreign_keys=ON');
    $db->exec('PRAGMA busy_timeout=5000');
    $setup = new CentralPrintingSetup($db);
    $status = $arguments ? $setup->prepare(rtrim(app_config('paths.backups'), '/').'/printing', 'cli') : $setup->status();
    echo json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
    if (!$status['erp_ready']) { fwrite(STDERR, "Preparação pendente: consulte os modelos na Configuração ERP. Não foram substituídas configurações existentes.\n"); exit(2); }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Preparação recusada: '.$exception->getMessage()."\n");
    exit(1);
}
