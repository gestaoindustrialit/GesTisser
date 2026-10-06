<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/app/Services/BackupManager.php';

$manager = new BackupManager($pdo, __DIR__);
if (!$manager->isDue()) {
    echo "Backup não devido.\n";
    exit(0);
}
try {
    $result = $manager->create('scheduled');
    echo 'Backup criado: ' . $result['filename'] . "\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Falha no backup: ' . $exception->getMessage() . "\n");
    exit(1);
}
