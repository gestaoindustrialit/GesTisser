<?php
declare(strict_types=1);

/**
 * Serviço autocontido de cópias de segurança. Não depende do bootstrap da
 * aplicação para também poder ser usado pela consola de recuperação.
 */
final class BackupManager
{
    /** @var string */
    private $root;
    /** @var string */
    private $storage;
    /** @var string */
    private $backupDir;
    /** @var string */
    private $database;

    public function __construct(string $root)
    {
        $this->root = rtrim($root, DIRECTORY_SEPARATOR);
        $this->storage = $this->root . '/storage';
        $this->backupDir = $this->storage . '/backups';
        $this->database = $this->root . '/database.sqlite';
        $this->ensureDirectory($this->backupDir);
    }

    public function settings(): array
    {
        $defaults = ['enabled' => false, 'interval_minutes' => 1440, 'retention' => 14, 'include_files' => true, 'last_run_at' => null, 'last_error' => null];
        $path = $this->storage . '/backup-settings.json';
        if (!is_file($path)) {
            return $defaults;
        }
        $value = json_decode((string) file_get_contents($path), true);
        return is_array($value) ? array_merge($defaults, $value) : $defaults;
    }

    public function saveSettings(array $input): array
    {
        $current = $this->settings();
        $current['enabled'] = !empty($input['enabled']);
        $current['include_files'] = !empty($input['include_files']);
        $current['interval_minutes'] = max(5, min(525600, (int) ($input['interval_minutes'] ?? 1440)));
        $current['retention'] = max(1, min(365, (int) ($input['retention'] ?? 14)));
        $this->writeJson($this->storage . '/backup-settings.json', $current);
        return $current;
    }

    public function isDue(array $settings = null): bool
    {
        if ($settings === null) {
            $settings = $this->settings();
        }
        if (empty($settings['enabled'])) {
            return false;
        }
        $last = !empty($settings['last_run_at']) ? strtotime((string) $settings['last_run_at']) : false;
        return $last === false || time() - $last >= ((int) $settings['interval_minutes'] * 60);
    }

    public function runScheduled()
    {
        $settings = $this->settings();
        if (!$this->isDue($settings)) {
            return null;
        }
        try {
            $result = $this->create((bool) $settings['include_files'], 'scheduled');
            $settings['last_run_at'] = gmdate('c');
            $settings['last_error'] = null;
            $this->writeJson($this->storage . '/backup-settings.json', $settings);
            $this->prune((int) $settings['retention']);
            return $result;
        } catch (Throwable $e) {
            $settings['last_error'] = gmdate('c') . ' — ' . $e->getMessage();
            $this->writeJson($this->storage . '/backup-settings.json', $settings);
            throw $e;
        }
    }

    public function create(bool $includeFiles = true, string $reason = 'manual'): array
    {
        if (!extension_loaded('pdo_sqlite') || !class_exists('ZipArchive')) {
            throw new RuntimeException('O backup requer as extensões pdo_sqlite e zip.');
        }
        if (!is_file($this->database)) {
            throw new RuntimeException('A base de dados não existe.');
        }
        $lock = fopen($this->storage . '/backup.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Já existe uma operação de backup/restauro em curso.');
        }
        $stamp = gmdate('Ymd_His');
        $id = 'gestisser_' . $stamp . '_' . bin2hex(random_bytes(3));
        $temporaryDb = $this->backupDir . '/.' . $id . '.sqlite';
        $temporaryZip = $this->backupDir . '/.' . $id . '.zip';
        $finalZip = $this->backupDir . '/' . $id . '.zip';
        try {
            // Compatível com PHP 7.0: consolida o WAL e bloqueia escritores
            // enquanto o ficheiro principal é copiado.
            $pdo = new PDO('sqlite:' . $this->database);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec('PRAGMA busy_timeout=10000');
            $pdo->exec('PRAGMA wal_checkpoint(FULL)');
            $pdo->exec('BEGIN EXCLUSIVE');
            try {
                if (!copy($this->database, $temporaryDb)) {
                    throw new RuntimeException('Não foi possível copiar a base de dados.');
                }
                $pdo->exec('COMMIT');
            } catch (Throwable $e) {
                $pdo->exec('ROLLBACK');
                throw $e;
            }
            $check = new PDO('sqlite:' . $temporaryDb);
            if ((string) $check->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
                throw new RuntimeException('A verificação de integridade da cópia falhou.');
            }
            $tables = $check->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
            $manifest = [
                'format' => 1, 'application' => 'GesTisser', 'created_at' => gmdate('c'),
                'reason' => $reason, 'includes_files' => $includeFiles,
                'database_sha256' => hash_file('sha256', $temporaryDb), 'tables' => $tables,
            ];
            $zip = new ZipArchive();
            if ($zip->open($temporaryZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Não foi possível criar o arquivo de backup.');
            }
            $zip->addFile($temporaryDb, 'database.sqlite');
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            if ($includeFiles) {
                $this->addSolutionFiles($zip);
            }
            if (!$zip->close() || !rename($temporaryZip, $finalZip)) {
                throw new RuntimeException('Não foi possível finalizar o backup.');
            }
            return ['file' => basename($finalZip), 'path' => $finalZip, 'manifest' => $manifest, 'size' => filesize($finalZip)];
        } finally {
            @unlink($temporaryDb);
            @unlink($temporaryZip);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function backups(): array
    {
        $result = [];
        foreach (glob($this->backupDir . '/gestisser_*.zip') ?: [] as $path) {
            try {
                $manifest = $this->manifest(basename($path));
                $result[] = ['file' => basename($path), 'size' => filesize($path), 'modified_at' => filemtime($path), 'manifest' => $manifest];
            } catch (Throwable $e) {
                $result[] = ['file' => basename($path), 'size' => filesize($path), 'modified_at' => filemtime($path), 'manifest' => [], 'error' => $e->getMessage()];
            }
        }
        usort($result, static function (array $a, array $b): int {
            return $b['modified_at'] <=> $a['modified_at'];
        });
        return $result;
    }

    public function manifest(string $file): array
    {
        $path = $this->safeBackupPath($file);
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Backup inválido ou ilegível.');
        }
        $json = $zip->getFromName('manifest.json');
        $zip->close();
        $manifest = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($manifest) || ($manifest['application'] ?? '') !== 'GesTisser') {
            throw new RuntimeException('Manifesto do backup inválido.');
        }
        return $manifest;
    }

    public function restoreTables(string $file, array $requestedTables): array
    {
        $tables = array_values(array_unique(array_filter($requestedTables, static function ($value): bool {
            return is_string($value) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $value) === 1;
        })));
        if (!$tables) {
            throw new InvalidArgumentException('Selecione pelo menos uma tabela.');
        }
        $source = $this->extractDatabase($file);
        $safety = $this->create(false, 'before-partial-restore');
        $pdo = new PDO('sqlite:' . $this->database);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys=OFF');
        $pdo->exec('ATTACH DATABASE ' . $pdo->quote($source) . ' AS backup_source');
        try {
            $pdo->beginTransaction();
            foreach ($tables as $table) {
                $quoted = '"' . str_replace('"', '""', $table) . '"';
                $exists = $pdo->query("SELECT (SELECT count(*) FROM main.sqlite_master WHERE type='table' AND name=" . $pdo->quote($table) . ") * (SELECT count(*) FROM backup_source.sqlite_master WHERE type='table' AND name=" . $pdo->quote($table) . ")")->fetchColumn();
                if ((int) $exists !== 1) {
                    throw new RuntimeException('A tabela ' . $table . ' não existe nas duas bases de dados.');
                }
                $mainCols = $pdo->query('PRAGMA main.table_info(' . $quoted . ')')->fetchAll(PDO::FETCH_ASSOC);
                $backupCols = $pdo->query('PRAGMA backup_source.table_info(' . $quoted . ')')->fetchAll(PDO::FETCH_ASSOC);
                $available = array_column($backupCols, 'name');
                $columns = array_values(array_filter(array_column($mainCols, 'name'), static function ($column) use ($available): bool {
                    return in_array($column, $available, true);
                }));
                if (!$columns) {
                    throw new RuntimeException('Não existem colunas compatíveis em ' . $table . '.');
                }
                $columnSql = implode(',', array_map(static function ($column): string {
                    return '"' . str_replace('"', '""', $column) . '"';
                }, $columns));
                $pdo->exec('DELETE FROM main.' . $quoted);
                $pdo->exec('INSERT INTO main.' . $quoted . ' (' . $columnSql . ') SELECT ' . $columnSql . ' FROM backup_source.' . $quoted);
            }
            $violations = $pdo->query('PRAGMA main.foreign_key_check')->fetchAll(PDO::FETCH_ASSOC);
            if ($violations) {
                throw new RuntimeException('O restauro criaria relações inválidas. Inclua também as tabelas relacionadas.');
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        } finally {
            $pdo->exec('DETACH DATABASE backup_source');
            @unlink($source);
        }
        return ['tables' => $tables, 'safety_backup' => $safety['file']];
    }

    public function restoreDatabase(string $file): array
    {
        $source = $this->extractDatabase($file);
        $safety = $this->create(false, 'before-full-restore');
        $replacement = $this->database . '.restore-' . bin2hex(random_bytes(4));
        $pdo = new PDO('sqlite:' . $this->database);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA busy_timeout=10000');
        $pdo->exec('PRAGMA wal_checkpoint(FULL)');
        $pdo->exec('BEGIN EXCLUSIVE');
        try {
            if (!copy($source, $replacement)) {
                throw new RuntimeException('Não foi possível preparar a reposição da base de dados.');
            }
            @chmod($replacement, 0640);
            if (!rename($replacement, $this->database)) {
                throw new RuntimeException('Não foi possível substituir a base de dados.');
            }
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            @unlink($replacement);
            $pdo->exec('ROLLBACK');
            throw $e;
        }
        @unlink($source);
        return ['safety_backup' => $safety['file']];
    }

    public function restoreSolutionFiles(string $file): array
    {
        $path = $this->safeBackupPath($file);
        $manifest = $this->manifest($file);
        if (empty($manifest['includes_files'])) {
            throw new RuntimeException('Este backup não contém ficheiros da solução.');
        }
        $safety = $this->create(true, 'before-files-restore');
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) throw new RuntimeException('Backup inválido.');
        $restored = 0;
        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entry = (string) $zip->getNameIndex($index);
                if (!$this->startsWith($entry, 'files/') || $this->endsWith($entry, '/')) continue;
                $relative = substr($entry, 6);
                if ($relative === '' || strpos($relative, "\0") !== false || preg_match('#(^|/)\.\.(/|$)#', $relative)) throw new RuntimeException('Caminho inseguro no arquivo.');
                if ($relative === 'database.sqlite' || $relative === 'storage/recovery.token' || $this->startsWith($relative, 'storage/backups/')) continue;
                $destination = $this->root . '/' . $relative;
                $this->ensureDirectory(dirname($destination));
                $input = $zip->getStream($entry);
                if (!$input) throw new RuntimeException('Não foi possível ler ' . $relative . '.');
                $temporary = $destination . '.restore-' . bin2hex(random_bytes(3));
                $output = fopen($temporary, 'wb');
                if (!$output) { fclose($input); throw new RuntimeException('Não foi possível escrever ' . $relative . '.'); }
                stream_copy_to_stream($input, $output); fclose($input); fclose($output);
                if (!rename($temporary, $destination)) { @unlink($temporary); throw new RuntimeException('Não foi possível substituir ' . $relative . '.'); }
                $restored++;
            }
        } finally { $zip->close(); }
        return ['files' => $restored, 'safety_backup' => $safety['file']];
    }

    public function prune(int $keep)
    {
        $items = $this->backups();
        foreach (array_slice($items, max(1, $keep)) as $item) @unlink($this->backupDir . '/' . $item['file']);
    }

    private function extractDatabase(string $file): string
    {
        $zip = new ZipArchive();
        if ($zip->open($this->safeBackupPath($file)) !== true) throw new RuntimeException('Backup inválido.');
        $data = $zip->getFromName('database.sqlite'); $zip->close();
        if (!is_string($data)) throw new RuntimeException('O backup não contém a base de dados.');
        $path = $this->backupDir . '/.restore_' . bin2hex(random_bytes(6)) . '.sqlite';
        file_put_contents($path, $data, LOCK_EX);
        $check = new PDO('sqlite:' . $path);
        if ((string) $check->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') { @unlink($path); throw new RuntimeException('Base de dados do backup corrompida.'); }
        return $path;
    }

    private function addSolutionFiles(ZipArchive $zip)
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->isLink()) continue;
            $relative = substr($file->getPathname(), strlen($this->root) + 1);
            if ($relative === 'database.sqlite' || $relative === 'storage/recovery.token' || $this->startsWith($relative, '.git/') || $this->startsWith($relative, 'storage/backups/') || $this->startsWith($relative, 'storage/logs/') || preg_match('/database\.sqlite-(wal|shm)$/', $relative)) continue;
            $zip->addFile($file->getPathname(), 'files/' . $relative);
        }
    }

    private function safeBackupPath(string $file): string
    {
        $name = basename($file);
        if ($name !== $file || !preg_match('/^gestisser_[A-Za-z0-9_\-]+\.zip$/', $name)) throw new InvalidArgumentException('Nome de backup inválido.');
        $path = $this->backupDir . '/' . $name;
        if (!is_file($path)) throw new RuntimeException('Backup não encontrado.');
        return $path;
    }

    private function startsWith(string $value, string $prefix): bool { return $prefix === '' || strncmp($value, $prefix, strlen($prefix)) === 0; }
    private function endsWith(string $value, string $suffix): bool { return $suffix === '' || substr($value, -strlen($suffix)) === $suffix; }
    private function ensureDirectory(string $path) { if (!is_dir($path) && !mkdir($path, 0750, true) && !is_dir($path)) throw new RuntimeException('Não foi possível criar ' . $path); }
    private function writeJson(string $path, array $value) { $this->ensureDirectory(dirname($path)); $tmp = $path . '.tmp'; file_put_contents($tmp, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX); rename($tmp, $path); @chmod($path, 0640); }
}
