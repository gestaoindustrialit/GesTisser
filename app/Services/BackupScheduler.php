<?php
declare(strict_types=1);

require_once __DIR__ . '/BackupManager.php';

final class BackupScheduler
{
    public static function runDue(PDO $pdo, string $root)
    {
        $schedule = $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='backup_schedule' LIMIT 1")->fetchColumn();
        if (!in_array($schedule, ['daily', 'weekly', 'monthly'], true)) {
            return false;
        }

        $directory = rtrim($root, '/\\') . '/storage/backups';
        if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('Não foi possível preparar o agendador de backups.');
        }

        $lock = @fopen($directory . '/scheduler.lock', 'c+');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) fclose($lock);
            return false;
        }

        try {
            $manager = new BackupManager($pdo, $root);
            if (!$manager->isDue()) {
                return false;
            }

            rewind($lock);
            $lastAttempt = (int) trim((string) stream_get_contents($lock));
            if ($lastAttempt > 0 && time() - $lastAttempt < 300) {
                return false;
            }
            ftruncate($lock, 0);
            rewind($lock);
            fwrite($lock, (string) time());
            fflush($lock);

            $manager->create('scheduled');
            return true;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
