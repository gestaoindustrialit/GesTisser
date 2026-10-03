<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/Services/BackupManager.php';

$serviceSource = file_get_contents(dirname(__DIR__) . '/app/Services/BackupManager.php');
$recoverySource = file_get_contents(dirname(__DIR__) . '/backup_recovery.php');
$php8Patterns = ['/\bfn\s*\(/', '/\?\?=/', '/\bstr_(starts|ends|contains)_with\s*\(/', '/private\s+(string|int|bool|array)\s+\$/'];
foreach ($php8Patterns as $pattern) {
    if (preg_match($pattern, $serviceSource . "\n" . $recoverySource)) {
        throw new RuntimeException('Foi encontrada sintaxe posterior ao PHP 7.0: ' . $pattern);
    }
}

$root = sys_get_temp_dir() . '/gestisser_backup_test_' . bin2hex(random_bytes(4));
mkdir($root . '/storage/backups', 0750, true);
file_put_contents($root . '/example.txt', 'solution file');
$db = new PDO('sqlite:' . $root . '/database.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE example (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
$db->exec("INSERT INTO example(value) VALUES ('original')");

try {
    $manager = new BackupManager($root);
    $settings = $manager->saveSettings(['enabled' => '1', 'include_files' => '1', 'interval_minutes' => 1, 'retention' => 0]);
    if ($settings['interval_minutes'] !== 5 || $settings['retention'] !== 1 || !$manager->isDue($settings)) throw new RuntimeException('Settings validation failed');
    $backup = $manager->create(true, 'automated-test');
    $manifest = $manager->manifest($backup['file']);
    if (!in_array('example', $manifest['tables'], true) || !$manifest['includes_files']) throw new RuntimeException('Manifest failed');
    $zip = new ZipArchive(); $zip->open($backup['path']);
    if ($zip->locateName('files/example.txt') === false) throw new RuntimeException('Solution files missing');
    $zip->close();

    file_put_contents($root . '/example.txt', 'changed solution file');
    $filesResult = $manager->restoreSolutionFiles($backup['file']);
    if (file_get_contents($root . '/example.txt') !== 'solution file' || $filesResult['files'] < 1) throw new RuntimeException('Solution restore failed');

    $db->exec("UPDATE example SET value='changed'");
    $result = $manager->restoreTables($backup['file'], ['example']);
    if ($db->query('SELECT value FROM example')->fetchColumn() !== 'original' || empty($result['safety_backup'])) throw new RuntimeException('Partial restore failed');

    $db->exec("UPDATE example SET value='changed-again'");
    $manager->restoreDatabase($backup['file']);
    $verification = new PDO('sqlite:' . $root . '/database.sqlite');
    if ($verification->query('SELECT value FROM example')->fetchColumn() !== 'original') throw new RuntimeException('Full restore failed');
    echo "Backup manager tests passed\n";
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    @rmdir($root);
}
