<?php
require_once __DIR__.'/CentralPrinting.php';

/** Prepare the ERP catalogue without contacting agents or creating print jobs. */
class CentralPrintingSetup
{
    private $db;
    private $printing;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->printing = new CentralPrinting($db);
    }

    private function definitions()
    {
        return [
            ['code'=>'prepared_ink_100x50', 'name'=>'Tinta preparada · 100 × 50 mm', 'type'=>'label', 'format'=>'100x50', 'renderer'=>'prepared_ink_label', 'width'=>100, 'height'=>50],
            ['code'=>'raw_material_roll_100x50', 'name'=>'Identificação de rolo · 100 × 50 mm', 'type'=>'label', 'format'=>'100x50', 'renderer'=>'raw_material_roll_label', 'width'=>100, 'height'=>50],
            ['code'=>'raw_material_ink_100x50', 'name'=>'Identificação de tinta · 100 × 50 mm', 'type'=>'label', 'format'=>'100x50', 'renderer'=>'raw_material_ink_label', 'width'=>100, 'height'=>50],
            ['code'=>'finished_product_a4', 'name'=>'Produto acabado · A4', 'type'=>'document', 'format'=>'A4', 'renderer'=>'product_label', 'width'=>210, 'height'=>297],
        ];
    }

    private function modelExists(array $definition, $activeOnly)
    {
        $statement = $this->db->prepare('SELECT m.id FROM erp_print_templates m JOIN erp_print_types t ON t.id=m.type_id JOIN erp_print_formats f ON f.id=m.format_id WHERE m.renderer=? AND t.code=? AND f.width_mm=? AND f.height_mm=?'.($activeOnly ? ' AND m.active=1' : '').' LIMIT 1');
        $statement->execute([$definition['renderer'], $definition['type'], $definition['width'], $definition['height']]);
        return (bool) $statement->fetchColumn();
    }

    private function snapshotCatalog($directory)
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true)) throw new RuntimeException('Não foi possível criar a pasta de backup.');
        if (file_put_contents($directory.'/.htaccess', "Require all denied\n", LOCK_EX) === false) throw new RuntimeException('Não foi possível proteger o backup.');
        $sql = '-- Catálogo de impressão GesTisser UTC '.gmdate('c')."\nBEGIN;\n";
        foreach (['erp_print_types', 'erp_print_formats', 'erp_print_templates'] as $table) {
            $statement = $this->db->prepare("SELECT sql FROM sqlite_master WHERE type='table' AND name=?");
            $statement->execute([$table]);
            $sql .= $statement->fetchColumn().";\n";
            foreach ($this->db->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $values = [];
                foreach ($row as $value) $values[] = $value === null ? 'NULL' : $this->db->quote((string) $value);
                $sql .= 'INSERT INTO '.$table.' VALUES ('.implode(',', $values).");\n";
            }
        }
        $path = $directory.'/catalog-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.sql';
        if (file_put_contents($path, $sql."COMMIT;\n", LOCK_EX) === false) throw new RuntimeException('Backup do catálogo falhou.');
        chmod($path, 0600);
    }

    public function prepare($backupDirectory, $actor)
    {
        $this->printing->migrate($backupDirectory);
        $created = 0;
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $missing = [];
            foreach ($this->definitions() as $definition) {
                // Existing models, including deliberately disabled models, are preserved.
                if ($this->modelExists($definition, false)) continue;
                $candidate = $this->db->prepare('SELECT t.id FROM erp_print_types t JOIN erp_print_formats f ON f.code=? WHERE t.code=? AND f.width_mm=? AND f.height_mm=? AND NOT EXISTS (SELECT 1 FROM erp_print_templates WHERE code=?)');
                $candidate->execute([$definition['format'], $definition['type'], $definition['width'], $definition['height'], $definition['code']]);
                if ($candidate->fetchColumn() !== false) $missing[] = $definition;
            }
            if ($missing) {
                $this->snapshotCatalog($backupDirectory);
                $insert = $this->db->prepare('INSERT OR IGNORE INTO erp_print_templates(code,name,type_id,format_id,renderer) SELECT ?,?,t.id,f.id,? FROM erp_print_types t JOIN erp_print_formats f ON f.code=? WHERE t.code=? AND f.width_mm=? AND f.height_mm=?');
                foreach ($missing as $definition) {
                    $insert->execute([$definition['code'], $definition['name'], $definition['renderer'], $definition['format'], $definition['type'], $definition['width'], $definition['height']]);
                    $created += $insert->rowCount();
                }
            }
            $this->printing->audit($actor, 'erp_prepared', null, 'modelos_criados:'.$created);
            $this->db->exec('COMMIT');
        } catch (Throwable $exception) {
            $this->db->exec('ROLLBACK');
            throw $exception;
        }
        $status = $this->status();
        $status['models_created'] = $created;
        return $status;
    }

    public function status()
    {
        $status = ['installed'=>$this->printing->installed(), 'enabled'=>false, 'erp_ready'=>false, 'models'=>[], 'agents'=>0, 'agents_online'=>0, 'printers'=>0, 'associations'=>0];
        if (!$status['installed']) return $status;
        $status['enabled'] = $this->printing->enabled();
        $ready = true;
        foreach ($this->definitions() as $definition) {
            $available = $this->modelExists($definition, true);
            $status['models'][] = ['name'=>$definition['name'], 'ready'=>$available];
            $ready = $ready && $available;
        }
        $status['erp_ready'] = $ready;
        $status['agents'] = (int) $this->db->query('SELECT COUNT(*) FROM erp_print_agents WHERE active=1')->fetchColumn();
        $status['agents_online'] = (int) $this->db->query("SELECT COUNT(*) FROM erp_print_agents WHERE active=1 AND last_seen>datetime('now','-2 minutes')")->fetchColumn();
        $status['printers'] = (int) $this->db->query("SELECT COUNT(*) FROM erp_printers p JOIN erp_print_agents a ON a.id=p.agent_id AND a.active=1 WHERE p.is_active=1 AND COALESCE(p.windows_queue,'')<>''")->fetchColumn();
        $status['associations'] = (int) $this->db->query('SELECT COUNT(*) FROM erp_work_center_printers x JOIN erp_printers p ON p.id=x.printer_id AND p.is_active=1 JOIN erp_print_agents a ON a.id=p.agent_id AND a.active=1 JOIN erp_work_centers w ON w.id=x.work_center_id AND w.is_active=1 WHERE x.active=1')->fetchColumn();
        return $status;
    }
}
