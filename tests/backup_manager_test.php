<?php
require_once dirname(__DIR__) . '/app/Services/BackupManager.php';
require_once dirname(__DIR__) . '/app/Services/BackupScheduler.php';

function backup_assert($condition, $message)
{
    if (!$condition) throw new RuntimeException($message);
}

$root = sys_get_temp_dir() . '/gestisser_backup_test_' . bin2hex(random_bytes(4));
mkdir($root . '/uploads', 0750, true);
file_put_contents($root . '/uploads/prova.txt', 'anexo');
$database = $root . '/database.sqlite';
$pdo = new PDO('sqlite:' . $database);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE app_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec('CREATE TABLE sample (value TEXT); INSERT INTO sample VALUES ("preservado")');

$managerSource = file_get_contents(dirname(__DIR__) . '/app/Services/BackupManager.php');
$schedulerSource = file_get_contents(dirname(__DIR__) . '/app/Services/BackupScheduler.php');
$backupSources = $managerSource . "\n" . $schedulerSource;
backup_assert(strpos($backupSources, ': void') === false, 'Os serviços não podem usar retornos void, incompatíveis com PHP 7.0.');
backup_assert(!preg_match('/[,(]\s*\?[A-Za-z_]/', $backupSources), 'Os serviços não podem usar parâmetros nullable, incompatíveis com PHP 7.0.');
backup_assert(!preg_match('/\)\s*:\s*\?[A-Za-z_]/', $backupSources), 'Os serviços não podem usar retornos nullable, incompatíveis com PHP 7.0.');
backup_assert(!preg_match('/function\s+\w+\s*\([^)]*\b(?:string|int|float|bool)\s+\$/', $backupSources), 'Os serviços de backup devem evitar assinaturas que já causaram incompatibilidades no alojamento PHP 7.0.');
backup_assert(!preg_match('/\)\s*:\s*[A-Za-z_]/', $backupSources), 'Os serviços de backup devem evitar tipos de retorno para máxima compatibilidade com PHP 7.0.');

$manager = new BackupManager($pdo, $root);
$manager->ensureSchema();
$manager->saveSettings('weekly', '02:00', 3);
backup_assert(!$manager->isDue(new DateTimeImmutable('2026-10-06 03:00:00')), 'Um backup semanal não deve correr fora da segunda-feira.');
backup_assert($manager->isDue(new DateTimeImmutable('2026-10-05 03:00:00')), 'O backup semanal deve vencer na segunda-feira depois da hora configurada.');

$result = $manager->create('manual', 1);
$archive = $manager->pathFor($result['filename']);
backup_assert($archive !== null && is_file($archive), 'O arquivo de backup não foi criado.');
backup_assert(hash_file('sha256', $archive) === $result['checksum'], 'O checksum guardado não corresponde ao arquivo.');
$zip = new ZipArchive();
backup_assert($zip->open($archive) === true, 'O ZIP não pode ser aberto.');
backup_assert($zip->locateName('database.sqlite') !== false, 'A base de dados não está no backup.');
backup_assert($zip->getFromName('uploads/prova.txt') === 'anexo', 'Os uploads não foram incluídos.');
$restored = $root . '/restored.sqlite';
file_put_contents($restored, $zip->getFromName('database.sqlite'));
$zip->close();
$restoredPdo = new PDO('sqlite:' . $restored);
backup_assert($restoredPdo->query('SELECT value FROM sample')->fetchColumn() === 'preservado', 'A cópia SQLite não preservou os dados.');

$manager->saveSettings('daily', '00:00', 3);
backup_assert(BackupScheduler::runDue($pdo, $root), 'O agendador web não criou o backup diário vencido.');
$scheduledCount = (int) $pdo->query("SELECT COUNT(*) FROM backup_runs WHERE status='success' AND trigger_type='scheduled'")->fetchColumn();
backup_assert($scheduledCount === 1, 'O agendador não registou exatamente uma execução automática.');
backup_assert(!BackupScheduler::runDue($pdo, $root), 'O agendador repetiu um backup já executado no mesmo dia.');

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($iterator as $entry) $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
rmdir($root);
echo "Backup manager: OK\n";
