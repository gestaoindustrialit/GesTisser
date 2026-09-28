<?php
declare(strict_types=1);

/**
 * Side-effect free diagnosis and deliberately small, transactional activation
 * layer.  This file must not load bootstrap/app.php: doing so would migrate a
 * database before the administrator has seen the diagnosis and backup screen.
 */
class CopyActivationService
{
    const SCHEMA_VERSION = 1;

    private $root;
    private $databasePath;
    private $storagePath;

    public function __construct($root, $databasePath = null, $storagePath = null)
    {
        $this->root = rtrim((string) $root, DIRECTORY_SEPARATOR);
        $this->databasePath = $databasePath ?: $this->root . '/database.sqlite';
        $this->storagePath = $storagePath ?: $this->root . '/storage';
    }

    public function databasePath()
    {
        return $this->databasePath;
    }

    public function lockPath()
    {
        return $this->storagePath . '/install.lock';
    }

    public function isLocked()
    {
        return is_file($this->lockPath());
    }

    public function diagnose()
    {
        $result = array(
            'state' => 'new', 'valid' => false, 'compatible' => false,
            'exists' => is_file($this->databasePath), 'path' => $this->databasePath,
            'size' => 0, 'modified_at' => null, 'table_count' => 0,
            'integrity' => 'não executada', 'foreign_keys' => array(),
            'schema_version' => 0, 'data_count' => 0, 'users' => 0,
            'administrators' => 0, 'environment' => $this->readEnvironment(),
            'missing_tables' => array(), 'missing_columns' => array(),
            'pending_migrations' => array(), 'errors' => array(),
            'readable' => false, 'writable' => false, 'directory_writable' => is_writable(dirname($this->databasePath)),
        );
        if (!$result['exists'] || filesize($this->databasePath) === 0) {
            $result['state'] = $result['exists'] ? 'empty' : 'new';
            $result['writable'] = !$result['exists'] || is_writable($this->databasePath);
            return $result;
        }
        $result['size'] = (int) filesize($this->databasePath);
        $result['modified_at'] = date('Y-m-d H:i:s', (int) filemtime($this->databasePath));
        $result['readable'] = is_readable($this->databasePath);
        $result['writable'] = is_writable($this->databasePath);
        if (!$result['readable']) {
            $result['state'] = 'invalid';
            $result['errors'][] = 'O ficheiro da base não permite leitura.';
            return $result;
        }
        try {
            $pdo = $this->connect(true);
            $integrity = $pdo->query('PRAGMA integrity_check')->fetchAll(PDO::FETCH_COLUMN);
            $result['integrity'] = count($integrity) === 1 && strtolower((string) $integrity[0]) === 'ok' ? 'ok' : 'falhou';
            $result['foreign_keys'] = $pdo->query('PRAGMA foreign_key_check')->fetchAll(PDO::FETCH_ASSOC);
            $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
            $result['table_count'] = count($tables);
            if (!$tables) {
                $result['state'] = 'empty';
                return $result;
            }
            $required = $this->requiredSchema();
            foreach ($required as $table => $columns) {
                if (!in_array($table, $tables, true)) {
                    $result['missing_tables'][] = $table;
                    continue;
                }
                $actual = $this->columns($pdo, $table);
                foreach ($columns as $column) {
                    if (!in_array($column, $actual, true)) {
                        $result['missing_columns'][] = $table . '.' . $column;
                    }
                }
            }
            foreach ($tables as $table) {
                if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) { continue; }
                $result['data_count'] += (int) $pdo->query('SELECT COUNT(*) FROM "' . $table . '"')->fetchColumn();
            }
            if (in_array('users', $tables, true)) {
                $result['users'] = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
                if (in_array('is_admin', $this->columns($pdo, 'users'), true)) {
                    $result['administrators'] = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE is_admin=1 AND COALESCE(is_active,1)=1 AND TRIM(COALESCE(password,""))<>""')->fetchColumn();
                }
            }
            if (in_array('gestisser_schema_migrations', $tables, true)) {
                $result['schema_version'] = (int) $pdo->query('SELECT COALESCE(MAX(version),0) FROM gestisser_schema_migrations WHERE status="completed"')->fetchColumn();
                $incomplete = (int) $pdo->query('SELECT COUNT(*) FROM gestisser_schema_migrations WHERE status<>"completed" OR finished_at IS NULL')->fetchColumn();
                if ($incomplete) { $result['errors'][] = 'Foi detetada uma migração incompleta.'; }
            }
            if ($result['schema_version'] > self::SCHEMA_VERSION) {
                $result['errors'][] = 'A versão do esquema é mais recente do que o código instalado.';
            }
            if ($result['schema_version'] < self::SCHEMA_VERSION) { $result['pending_migrations'][] = '001_activation_metadata'; }
            $recognized = count($result['missing_tables']) === 0 && count($result['missing_columns']) === 0;
            $result['valid'] = $recognized && $result['integrity'] === 'ok' && !$result['foreign_keys'] && $result['users'] > 0;
            $result['compatible'] = $result['valid'] && !$result['errors'];
            $result['state'] = $result['compatible'] ? 'existing' : 'invalid';
        } catch (Throwable $exception) {
            $result['state'] = 'invalid';
            $result['errors'][] = 'O ficheiro não é uma base SQLite GesTISSER válida.';
        }
        if (!$result['writable'] || !$result['directory_writable']) {
            $result['compatible'] = false;
            $result['errors'][] = 'A base e o respetivo diretório têm de permitir leitura e escrita.';
        }
        return $result;
    }

    public function createVerifiedBackup()
    {
        if (!is_file($this->databasePath)) { throw new RuntimeException('A base original não existe.'); }
        $dir = $this->storagePath . '/backups';
        if (!is_dir($dir) && !@mkdir($dir, 0750, true)) { throw new RuntimeException('Não foi possível criar a pasta segura de backups.'); }
        $path = $dir . '/pre_activation_' . gmdate('Ymd_His') . '_' . substr($this->uuid(), 0, 8) . '.sqlite';
        $sourceHash = hash_file('sha256', $this->databasePath);
        if ($sourceHash === false || !@copy($this->databasePath, $path)) { throw new RuntimeException('A criação do backup obrigatório falhou.'); }
        @chmod($path, 0640);
        $backupHash = hash_file('sha256', $path);
        if ($backupHash === false || !hash_equals($sourceHash, $backupHash)) {
            @unlink($path);
            throw new RuntimeException('O backup não passou a validação SHA-256.');
        }
        return array('path' => $path, 'sha256' => $sourceHash);
    }

    public function activate($environment, $productionDatabasePath = null)
    {
        if (!in_array($environment, array('production', 'test', 'development'), true)) { throw new InvalidArgumentException('Ambiente inválido.'); }
        $diagnosis = $this->diagnose();
        if (!$diagnosis['compatible']) { throw new RuntimeException('A base não passou o diagnóstico de segurança.'); }
        if ($environment === 'test') { $this->assertSeparatedFromProduction($productionDatabasePath); }
        $this->assertNotInUse();
        $before = $this->snapshot();
        $backup = $this->createVerifiedBackup();
        $pdo = $this->connect(false);
        $applied = array();
        try {
            $pdo->exec('BEGIN IMMEDIATE');
            $pdo->exec('CREATE TABLE IF NOT EXISTS gestisser_schema_migrations (version INTEGER PRIMARY KEY, name TEXT NOT NULL UNIQUE, status TEXT NOT NULL, started_at DATETIME NOT NULL, finished_at DATETIME, error_message TEXT)');
            $exists = $pdo->query('SELECT COUNT(*) FROM gestisser_schema_migrations WHERE version=1 AND status="completed"')->fetchColumn();
            if (!(int) $exists) {
                $statement = $pdo->prepare('INSERT OR REPLACE INTO gestisser_schema_migrations(version,name,status,started_at,finished_at,error_message) VALUES (1,?,"completed",CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,NULL)');
                $statement->execute(array('001_activation_metadata'));
                $applied[] = '001_activation_metadata';
            }
            if ($environment === 'test') {
                if ($this->tableExists($pdo, 'app_settings')) {
                    foreach (array('hr_alerts_inline_cron_enabled', 'external_notifications_enabled', 'webhooks_enabled', 'microsoft_graph_enabled', 'toconline_enabled') as $key) {
                        $this->setSetting($pdo, $key, '0');
                    }
                }
                if ($this->tableExists($pdo, 'integrations')) { $pdo->exec('UPDATE integrations SET is_active=0,status="off"'); }
                if ($this->tableExists($pdo, 'integration_flows')) { $pdo->exec('UPDATE integration_flows SET is_active=0'); }
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw new RuntimeException('A ativação falhou; nenhuma migração posterior foi executada. O backup foi mantido.');
        }
        $after = $this->snapshot();
        $this->assertPreserved($before, $after);
        $post = $this->diagnose();
        if ($post['integrity'] !== 'ok' || $post['foreign_keys']) { throw new RuntimeException('A validação posterior à migração falhou. O backup foi mantido.'); }
        if ($environment === 'test' && $productionDatabasePath && is_file($productionDatabasePath)
            && hash_file('sha256', $productionDatabasePath) === hash_file('sha256', $this->databasePath)) {
            throw new RuntimeException('A impressão digital da base de teste ainda coincide com a produção. O backup foi mantido.');
        }
        $config = array(
            'installation_uuid' => $this->uuid(), 'environment' => $environment,
            'database_path' => $this->databasePath, 'database_fingerprint' => hash_file('sha256', $this->databasePath),
            'installed_at' => gmdate('c'), 'activated_from_copy_at' => gmdate('c'),
            'external_services_enabled' => $environment === 'production',
            'cron_enabled' => $environment === 'production',
            'uploads_path' => $this->storagePath . '/uploads/' . $environment,
            'logs_path' => $this->storagePath . '/logs/' . $environment,
            'session_name' => 'gestisser_' . $environment . '_' . substr(hash('sha256', $this->databasePath), 0, 12),
        );
        $this->writeInstallationConfig($config);
        $this->writeLock($config);
        return array('backup' => $backup, 'migrations' => $applied, 'config' => $config);
    }

    public function snapshot()
    {
        $pdo = $this->connect(true);
        $tables = array('users','erp_customers','erp_suppliers','erp_finished_products','erp_raw_materials','erp_production_orders','erp_stock_movements','erp_stock_balances');
        $snapshot = array('counts' => array(), 'passwords' => '', 'stock_movements' => '', 'stock_balances' => '');
        foreach ($tables as $table) {
            if ($this->tableExists($pdo, $table)) { $snapshot['counts'][$table] = (int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn(); }
        }
        if ($this->tableExists($pdo, 'users')) { $snapshot['passwords'] = $this->tableDigest($pdo, 'users', array('id','password')); }
        if ($this->tableExists($pdo, 'erp_stock_movements')) { $snapshot['stock_movements'] = $this->tableDigest($pdo, 'erp_stock_movements'); }
        if ($this->tableExists($pdo, 'erp_stock_balances')) { $snapshot['stock_balances'] = $this->tableDigest($pdo, 'erp_stock_balances'); }
        return $snapshot;
    }

    private function requiredSchema()
    {
        return array(
            'users'=>array('id','password','is_admin','is_active'), 'erp_customers'=>array('id','code','name'),
            'erp_suppliers'=>array('id','code','name'), 'erp_finished_products'=>array('id','code','description'),
            'erp_raw_materials'=>array('id','code','description'), 'erp_production_orders'=>array('id','order_number'),
            'erp_stock_movements'=>array('id','quantity'), 'erp_stock_balances'=>array('item_type','item_id','physical_qty'),
        );
    }

    private function connect($readOnly)
    {
        $pdo = new PDO('sqlite:' . $this->databasePath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_TIMEOUT, 5);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        if ($readOnly) { $pdo->exec('PRAGMA query_only = ON'); }
        return $pdo;
    }

    private function columns(PDO $pdo, $table)
    {
        $rows = $pdo->query('PRAGMA table_info("' . str_replace('"', '""', $table) . '")')->fetchAll();
        $columns = array(); foreach ($rows as $row) { $columns[] = (string) $row['name']; } return $columns;
    }

    private function tableExists(PDO $pdo, $table)
    {
        $statement = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?"); $statement->execute(array($table)); return (bool) $statement->fetchColumn();
    }

    private function tableDigest(PDO $pdo, $table, $columns = array())
    {
        if (!$columns) { $columns = $this->columns($pdo, $table); }
        sort($columns); $quoted = array(); foreach ($columns as $column) { $quoted[] = '"' . str_replace('"', '""', $column) . '"'; }
        $rows = $pdo->query('SELECT ' . implode(',', $quoted) . ' FROM "' . $table . '" ORDER BY rowid')->fetchAll(PDO::FETCH_NUM);
        return hash('sha256', json_encode($rows));
    }

    private function assertPreserved($before, $after)
    {
        foreach ($before['counts'] as $table => $count) { if (!isset($after['counts'][$table]) || $after['counts'][$table] < $count) { throw new RuntimeException('A contagem de uma tabela crítica diminuiu. O backup foi mantido.'); } }
        foreach (array('passwords','stock_movements','stock_balances') as $key) { if (!hash_equals((string) $before[$key], (string) $after[$key])) { throw new RuntimeException('Dados protegidos foram alterados. O backup foi mantido.'); } }
    }

    private function setSetting(PDO $pdo, $key, $value)
    {
        $update = $pdo->prepare('UPDATE app_settings SET setting_value=? WHERE setting_key=?'); $update->execute(array($value,$key));
        if ($update->rowCount() === 0) { $insert = $pdo->prepare('INSERT INTO app_settings(setting_key,setting_value) VALUES (?,?)'); try { $insert->execute(array($key,$value)); } catch (PDOException $e) { $update->execute(array($value,$key)); } }
    }

    private function assertSeparatedFromProduction($productionPath)
    {
        $productionPath = $productionPath ?: getenv('GESTISSER_PRODUCTION_DB_PATH');
        if (!$productionPath) { return; }
        $candidate = realpath($this->databasePath) ?: $this->databasePath; $production = realpath($productionPath) ?: $productionPath;
        if ($candidate === $production) { throw new RuntimeException('A base de teste coincide com a base de produção configurada.'); }
    }

    private function assertNotInUse()
    {
        $pdo = $this->connect(false);
        try {
            $pdo->exec('PRAGMA locking_mode = EXCLUSIVE');
            $pdo->exec('BEGIN EXCLUSIVE');
            $pdo->rollBack();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw new RuntimeException('A base está em uso por outro processo ou caminho conhecido. A ativação foi cancelada.');
        }
    }

    private function readEnvironment()
    {
        $path = $this->storagePath . '/installation.json'; if (!is_file($path)) { return 'não configurado'; }
        $data = json_decode((string) @file_get_contents($path), true); return is_array($data) && isset($data['environment']) ? $data['environment'] : 'não configurado';
    }

    private function writeInstallationConfig($config)
    {
        if (!is_dir($this->storagePath) && !@mkdir($this->storagePath, 0750, true)) { throw new RuntimeException('Não foi possível guardar a configuração da instalação.'); }
        foreach (array($config['uploads_path'],$config['logs_path']) as $dir) { if (!is_dir($dir) && !@mkdir($dir,0750,true)) { throw new RuntimeException('Não foi possível criar o armazenamento isolado.'); } }
        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); $tmp = $this->storagePath . '/installation.json.tmp';
        if (@file_put_contents($tmp, $json, LOCK_EX) === false || !@rename($tmp, $this->storagePath . '/installation.json')) { throw new RuntimeException('Não foi possível guardar a configuração da instalação.'); }
        @chmod($this->storagePath . '/installation.json', 0640);
    }

    private function writeLock($config)
    {
        if (@file_put_contents($this->lockPath(), json_encode(array('installation_uuid'=>$config['installation_uuid'],'locked_at'=>gmdate('c'))), LOCK_EX) === false) { throw new RuntimeException('Não foi possível bloquear o instalador.'); }
        @chmod($this->lockPath(), 0640);
    }

    private function uuid()
    {
        $bytes = random_bytes(16); $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); $hex = bin2hex($bytes);
        return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
    }
}
