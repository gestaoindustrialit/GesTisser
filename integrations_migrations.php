<?php
declare(strict_types=1);

function integrations_migrate(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $db = (string) app_config('db_path');
    $marker = dirname($db) . '/storage/backups/integrations_schema_v1.done';
    if (!is_file($marker) && is_file($db)) {
        @mkdir(dirname($marker), 0750, true);
        @copy($db, dirname($marker) . '/pre_integrations_' . date('Ymd_His') . '.sqlite');
    }
    $sql = [
        'CREATE TABLE IF NOT EXISTS integrations (id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT NOT NULL,slug TEXT NOT NULL UNIQUE,provider TEXT NOT NULL DEFAULT "generic",description TEXT,is_active INTEGER NOT NULL DEFAULT 0,environment TEXT NOT NULL DEFAULT "sandbox",base_url TEXT NOT NULL,timeout INTEGER NOT NULL DEFAULT 20,retries INTEGER NOT NULL DEFAULT 2,retry_delay INTEGER NOT NULL DEFAULT 2,verify_ssl INTEGER NOT NULL DEFAULT 1,auth_type TEXT NOT NULL DEFAULT "none",status TEXT NOT NULL DEFAULT "off",last_result TEXT,created_by INTEGER,updated_by INTEGER,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)',
        'CREATE TABLE IF NOT EXISTS integration_credentials (id INTEGER PRIMARY KEY AUTOINCREMENT,integration_id INTEGER NOT NULL,key_name TEXT NOT NULL,value_encrypted TEXT NOT NULL,expires_at DATETIME,UNIQUE(integration_id,key_name),FOREIGN KEY(integration_id) REFERENCES integrations(id) ON DELETE CASCADE)',
        'CREATE TABLE IF NOT EXISTS integration_headers (id INTEGER PRIMARY KEY AUTOINCREMENT,integration_id INTEGER NOT NULL,kind TEXT NOT NULL DEFAULT "header",key_name TEXT NOT NULL,value TEXT,is_sensitive INTEGER NOT NULL DEFAULT 0,FOREIGN KEY(integration_id) REFERENCES integrations(id) ON DELETE CASCADE)',
        'CREATE TABLE IF NOT EXISTS integration_flows (id INTEGER PRIMARY KEY AUTOINCREMENT,integration_id INTEGER NOT NULL,name TEXT NOT NULL,description TEXT,is_active INTEGER NOT NULL DEFAULT 0,entity TEXT NOT NULL,direction TEXT NOT NULL DEFAULT "read",http_method TEXT NOT NULL DEFAULT "GET",endpoint TEXT NOT NULL,schedule TEXT NOT NULL DEFAULT "manual",payload_type TEXT NOT NULL DEFAULT "json",payload_template TEXT,response_items_path TEXT,remote_id_path TEXT,remote_code_path TEXT,error_path TEXT,pagination_type TEXT NOT NULL DEFAULT "none",page_parameter TEXT,limit_parameter TEXT,next_path TEXT,max_pages INTEGER DEFAULT 10,items_per_page INTEGER DEFAULT 100,unmatched_action TEXT DEFAULT "ignore",last_run_at DATETIME,last_success_at DATETIME,next_run_at DATETIME,last_duration_ms INTEGER,last_result TEXT,dry_run_passed_at DATETIME,created_by INTEGER,updated_by INTEGER,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(integration_id) REFERENCES integrations(id) ON DELETE CASCADE)',
        'CREATE TABLE IF NOT EXISTS integration_flow_headers (id INTEGER PRIMARY KEY AUTOINCREMENT,flow_id INTEGER NOT NULL,key_name TEXT NOT NULL,value TEXT,is_sensitive INTEGER NOT NULL DEFAULT 0,FOREIGN KEY(flow_id) REFERENCES integration_flows(id) ON DELETE CASCADE)',
        'CREATE TABLE IF NOT EXISTS integration_field_mappings (id INTEGER PRIMARY KEY AUTOINCREMENT,flow_id INTEGER NOT NULL,local_field TEXT NOT NULL,remote_field TEXT NOT NULL,is_required INTEGER DEFAULT 0,default_value TEXT,data_type TEXT DEFAULT "string",transformation TEXT DEFAULT "none",FOREIGN KEY(flow_id) REFERENCES integration_flows(id) ON DELETE CASCADE)',
        'CREATE TABLE IF NOT EXISTS integration_mappings (id INTEGER PRIMARY KEY AUTOINCREMENT,integration_id INTEGER NOT NULL,flow_id INTEGER NOT NULL,entity TEXT NOT NULL,local_id TEXT,local_code TEXT,remote_id TEXT,remote_code TEXT,status TEXT DEFAULT "active",UNIQUE(flow_id,local_id,remote_id),FOREIGN KEY(integration_id) REFERENCES integrations(id) ON DELETE CASCADE,FOREIGN KEY(flow_id) REFERENCES integration_flows(id) ON DELETE CASCADE)',
        'CREATE TABLE IF NOT EXISTS integration_filters (id INTEGER PRIMARY KEY AUTOINCREMENT,flow_id INTEGER NOT NULL,field_name TEXT NOT NULL,operator TEXT NOT NULL,value TEXT,FOREIGN KEY(flow_id) REFERENCES integration_flows(id) ON DELETE CASCADE)',
        'CREATE TABLE IF NOT EXISTS integration_runs (id INTEGER PRIMARY KEY AUTOINCREMENT,integration_id INTEGER NOT NULL,flow_id INTEGER,operation TEXT NOT NULL,status TEXT NOT NULL,dry_run INTEGER NOT NULL DEFAULT 0,records_read INTEGER DEFAULT 0,records_sent INTEGER DEFAULT 0,records_created INTEGER DEFAULT 0,records_updated INTEGER DEFAULT 0,records_skipped INTEGER DEFAULT 0,error_count INTEGER DEFAULT 0,duration_ms INTEGER DEFAULT 0,request_url TEXT,http_method TEXT,http_status INTEGER,payload TEXT,response TEXT,error_message TEXT,executed_by INTEGER,started_at DATETIME DEFAULT CURRENT_TIMESTAMP,finished_at DATETIME,FOREIGN KEY(integration_id) REFERENCES integrations(id) ON DELETE CASCADE,FOREIGN KEY(flow_id) REFERENCES integration_flows(id) ON DELETE SET NULL)',
        'CREATE TABLE IF NOT EXISTS integration_run_items (id INTEGER PRIMARY KEY AUTOINCREMENT,run_id INTEGER NOT NULL,local_code TEXT,operation TEXT,before_value TEXT,after_value TEXT,http_status INTEGER,status TEXT,message TEXT,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(run_id) REFERENCES integration_runs(id) ON DELETE CASCADE)',
        'CREATE INDEX IF NOT EXISTS idx_integration_flows_due ON integration_flows(is_active,next_run_at)',
        'CREATE INDEX IF NOT EXISTS idx_integration_runs_date ON integration_runs(started_at)'
    ];
    $pdo->beginTransaction();
    try { foreach ($sql as $q) $pdo->exec($q); $pdo->commit(); @touch($marker); }
    catch (Throwable $e) { $pdo->rollBack(); throw $e; }
}
