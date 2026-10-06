<?php
final class BackupManager
{
    private $pdo;
    private $root;
    private $backupDirectory;

    public function __construct(PDO $pdo, $root, $backupDirectory = null)
    {
        $this->pdo = $pdo;
        $this->root = rtrim($root, '/\\');
        $this->backupDirectory = $backupDirectory ?: $this->root . '/storage/backups';
    }

    public function ensureSchema()
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS backup_runs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            filename TEXT,
            status TEXT NOT NULL,
            trigger_type TEXT NOT NULL,
            size_bytes INTEGER,
            checksum TEXT,
            message TEXT,
            created_by INTEGER,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            finished_at DATETIME
        )');
        foreach (['backup_schedule' => 'disabled', 'backup_time' => '02:00', 'backup_retention' => '14'] as $key => $value) {
            $stmt = $this->pdo->prepare('INSERT OR IGNORE INTO app_settings(setting_key, setting_value) VALUES (?, ?)');
            $stmt->execute([$key, $value]);
            $stmt->closeCursor();
        }
    }

    public function settings()
    {
        $this->ensureSchema();
        $stmt = $this->pdo->query("SELECT setting_key, setting_value FROM app_settings WHERE setting_key IN ('backup_schedule','backup_time','backup_retention')");
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $stmt->closeCursor();
        return [
            'schedule' => (string) ($rows['backup_schedule'] ?? 'disabled'),
            'time' => (string) ($rows['backup_time'] ?? '02:00'),
            'retention' => (int) ($rows['backup_retention'] ?? 14),
        ];
    }

    public function saveSettings($schedule, $time, $retention)
    {
        if (!in_array($schedule, ['disabled', 'daily', 'weekly', 'monthly'], true)) {
            throw new InvalidArgumentException('Periodicidade inválida.');
        }
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) {
            throw new InvalidArgumentException('Hora inválida.');
        }
        $retention = max(1, min(365, $retention));
        foreach (['backup_schedule' => $schedule, 'backup_time' => $time, 'backup_retention' => (string) $retention] as $key => $value) {
            $stmt = $this->pdo->prepare('UPDATE app_settings SET setting_value=?, updated_at=CURRENT_TIMESTAMP WHERE setting_key=?');
            $stmt->execute([$value, $key]);
            $updated = $stmt->rowCount();
            $stmt->closeCursor();
            if ($updated === 0) {
                $insert = $this->pdo->prepare('INSERT OR IGNORE INTO app_settings(setting_key, setting_value, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP)');
                $insert->execute([$key, $value]);
                $insert->closeCursor();
            }
        }
    }

    public function isDue($now = null)
    {
        $settings = $this->settings();
        if ($settings['schedule'] === 'disabled') return false;
        $now = $now ?: new DateTimeImmutable('now');
        if ($now->format('H:i') < $settings['time']) return false;
        if ($settings['schedule'] === 'weekly' && $now->format('N') !== '1') return false;
        if ($settings['schedule'] === 'monthly' && $now->format('j') !== '1') return false;
        $lastStmt = $this->pdo->query("SELECT finished_at FROM backup_runs WHERE status='success' AND trigger_type='scheduled' ORDER BY id DESC LIMIT 1");
        $last = $lastStmt->fetchColumn();
        $lastStmt->closeCursor();
        if (!$last) return true;
        $lastDate = new DateTimeImmutable((string) $last);
        if ($settings['schedule'] === 'daily') return $lastDate->format('Y-m-d') !== $now->format('Y-m-d');
        if ($settings['schedule'] === 'weekly') return $lastDate->format('o-W') !== $now->format('o-W');
        return $lastDate->format('Y-m') !== $now->format('Y-m');
    }

    public function create($trigger = 'manual', $userId = null)
    {
        $this->ensureStorage();
        $this->ensureSchema();
        $run = $this->pdo->prepare("INSERT INTO backup_runs(status, trigger_type, created_by) VALUES ('running', ?, ?)");
        $run->execute([$trigger, $userId]);
        $runId = (int) $this->pdo->lastInsertId();
        $run->closeCursor();
        $run = null;
        $base = 'gestisser_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4));
        $snapshot = $this->backupDirectory . '/' . $base . '.sqlite.tmp';
        $archive = $this->backupDirectory . '/' . $base . '.zip';
        try {
            $this->createDatabaseSnapshotWithRetry($snapshot);
            $check = new PDO('sqlite:' . $snapshot);
            $integrityStmt = $check->query('PRAGMA integrity_check');
            $integrityResult = (string) $integrityStmt->fetchColumn();
            $integrityStmt->closeCursor();
            $check = null;
            if ($integrityResult !== 'ok') {
                throw new RuntimeException('A verificação de integridade da cópia falhou.');
            }
            $zip = new ZipArchive();
            if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
                throw new RuntimeException('Não foi possível criar o arquivo ZIP.');
            }
            $zip->addFile($snapshot, 'database.sqlite');
            $included = [];
            foreach (['uploads', 'assets/uploads', 'storage/uploads'] as $relative) {
                $directory = $this->root . '/' . $relative;
                if (is_dir($directory)) {
                    $this->addDirectory($zip, $directory, $relative, $included);
                }
            }
            $manifest = ['format' => 1, 'created_at' => date(DATE_ATOM), 'application' => 'GesTisser', 'database' => 'database.sqlite', 'files' => $included];
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            if (!$zip->close()) throw new RuntimeException('Não foi possível concluir o arquivo ZIP.');
            @unlink($snapshot);
            $size = (int) filesize($archive);
            $checksum = hash_file('sha256', $archive);
            $done = $this->pdo->prepare("UPDATE backup_runs SET filename=?, status='success', size_bytes=?, checksum=?, finished_at=CURRENT_TIMESTAMP WHERE id=?");
            $done->execute([basename($archive), $size, $checksum, $runId]);
            $done->closeCursor();
            $this->prune($this->settings()['retention']);
            return ['filename' => basename($archive), 'size_bytes' => $size, 'checksum' => $checksum];
        } catch (Throwable $exception) {
            @unlink($snapshot);
            @unlink($snapshot . '-wal');
            @unlink($snapshot . '-shm');
            @unlink($archive);
            $failed = $this->pdo->prepare("UPDATE backup_runs SET status='failed', message=?, finished_at=CURRENT_TIMESTAMP WHERE id=?");
            $failed->execute([$exception->getMessage(), $runId]);
            $failed->closeCursor();
            throw $exception;
        }
    }

    public function listRuns($limit = 100)
    {
        $this->ensureSchema();
        return $this->pdo->query('SELECT * FROM backup_runs ORDER BY id DESC LIMIT ' . max(1, min(500, $limit)))->fetchAll(PDO::FETCH_ASSOC);
    }

    public function pathFor($filename)
    {
        if (!preg_match('/^gestisser_\d{8}_\d{6}_[a-f0-9]{8}\.zip$/', $filename)) return null;
        $path = $this->backupDirectory . '/' . $filename;
        return is_file($path) ? $path : null;
    }

    private function ensureStorage()
    {
        if (!is_dir($this->backupDirectory) && !mkdir($this->backupDirectory, 0750, true) && !is_dir($this->backupDirectory)) {
            throw new RuntimeException('Não foi possível criar a pasta segura de backups.');
        }
        @file_put_contents($this->backupDirectory . '/.htaccess', "Require all denied\nDeny from all\n");
        @file_put_contents($this->backupDirectory . '/index.html', '');
    }

    private function addDirectory(ZipArchive $zip, $directory, $prefix, array &$included)
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->isLink()) continue;
            $relative = $prefix . '/' . substr($file->getPathname(), strlen($directory) + 1);
            $relative = str_replace('\\', '/', $relative);
            $zip->addFile($file->getPathname(), $relative);
            $included[] = $relative;
        }
    }

    private function createDatabaseSnapshot($snapshot)
    {
        $databasePath = null;
        $databaseListStmt = $this->pdo->query('PRAGMA database_list');
        $databases = $databaseListStmt->fetchAll(PDO::FETCH_ASSOC);
        $databaseListStmt->closeCursor();
        foreach ($databases as $database) {
            if ($database['name'] === 'main') {
                $databasePath = $database['file'];
                break;
            }
        }
        if (!$databasePath || !is_file($databasePath)) {
            throw new RuntimeException('Não foi possível localizar a base de dados SQLite.');
        }

        // Usa uma ligação dedicada para não herdar cursores do pedido web.
        // BEGIN IMMEDIATE impede novas escritas durante a curta cópia. Em modo
        // WAL copiamos também o WAL e consolidamo-lo apenas na cópia, evitando
        // executar wal_checkpoint na base em produção (que causava SQLITE_LOCKED).
        $source = new PDO('sqlite:' . $databasePath);
        $source->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $source->setAttribute(PDO::ATTR_TIMEOUT, 30);
        $source->exec('PRAGMA busy_timeout = 30000');
        $source->exec('BEGIN IMMEDIATE');
        try {
            if (!copy($databasePath, $snapshot)) {
                throw new RuntimeException('Não foi possível copiar a base de dados SQLite.');
            }
            if (is_file($databasePath . '-wal') && !copy($databasePath . '-wal', $snapshot . '-wal')) {
                throw new RuntimeException('Não foi possível copiar o WAL da base de dados SQLite.');
            }
        } finally {
            $source->exec('ROLLBACK');
            $source = null;
        }

        $snapshotPdo = new PDO('sqlite:' . $snapshot);
        $snapshotPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $snapshotPdo->exec('PRAGMA busy_timeout = 30000');
        $snapshotPdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        $snapshotPdo = null;
        @unlink($snapshot . '-wal');
        @unlink($snapshot . '-shm');
    }

    private function createDatabaseSnapshotWithRetry($snapshot)
    {
        $lastException = null;
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            @unlink($snapshot);
            @unlink($snapshot . '-wal');
            @unlink($snapshot . '-shm');
            try {
                $this->createDatabaseSnapshot($snapshot);
                return;
            } catch (Throwable $exception) {
                $lastException = $exception;
                $message = strtolower($exception->getMessage());
                if (strpos($message, 'locked') === false && strpos($message, 'ocupada') === false) throw $exception;
                if ($attempt < 3) usleep(500000 * $attempt);
            }
        }
        throw new RuntimeException('A base de dados permaneceu ocupada após 3 tentativas. Tente novamente.', 0, $lastException);
    }

    private function prune($keep)
    {
        $files = glob($this->backupDirectory . '/gestisser_*.zip') ?: [];
        usort($files, static function ($a, $b) { return filemtime($b) <=> filemtime($a); });
        foreach (array_slice($files, max(1, $keep)) as $file) @unlink($file);
    }
}

final class BackupScheduler
{
    public static function runDue(PDO $pdo, $root)
    {
        $scheduleStmt = $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='backup_schedule' LIMIT 1");
        $schedule = $scheduleStmt->fetchColumn();
        $scheduleStmt->closeCursor();
        if (!in_array($schedule, ['daily', 'weekly', 'monthly'], true)) return false;

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
            if (!$manager->isDue()) return false;
            rewind($lock);
            $lastAttempt = (int) trim((string) stream_get_contents($lock));
            if ($lastAttempt > 0 && time() - $lastAttempt < 300) return false;
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
