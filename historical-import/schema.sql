-- Apply only through the explicit administrative setup action, never during dry-run.
CREATE TABLE IF NOT EXISTS erp_legacy_import_runs (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 started_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 finished_at TEXT,
 user_id INTEGER NOT NULL REFERENCES users(id),
 filename TEXT NOT NULL,
 entity TEXT NOT NULL,
 rows_total INTEGER NOT NULL DEFAULT 0,
 rows_inserted INTEGER NOT NULL DEFAULT 0,
 rows_updated INTEGER NOT NULL DEFAULT 0,
 rows_skipped INTEGER NOT NULL DEFAULT 0,
 rows_errors INTEGER NOT NULL DEFAULT 0,
 dry_run INTEGER NOT NULL DEFAULT 0 CHECK(dry_run IN (0,1)),
 status TEXT NOT NULL CHECK(status IN ('running','success','failed')),
 log TEXT,
 backup_path TEXT,
 batch_hash TEXT,
 schema_hash TEXT
);
CREATE TABLE IF NOT EXISTS erp_legacy_import_map (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 source_system TEXT NOT NULL DEFAULT 'Bobinas',
 entity_type TEXT NOT NULL,
 legacy_id TEXT NOT NULL,
 gestisser_id INTEGER NOT NULL,
 target_table TEXT NOT NULL,
 legacy_code TEXT,
 legacy_document_number TEXT,
 imported_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 import_run_id INTEGER NOT NULL REFERENCES erp_legacy_import_runs(id),
 source_hash TEXT NOT NULL,
 original_data_json TEXT NOT NULL,
 UNIQUE(source_system,entity_type,legacy_id)
);
CREATE INDEX IF NOT EXISTS idx_legacy_map_target ON erp_legacy_import_map(target_table,gestisser_id);
CREATE TABLE IF NOT EXISTS erp_legacy_import_logs (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 import_run_id INTEGER NOT NULL REFERENCES erp_legacy_import_runs(id),
 entity_type TEXT NOT NULL,
 row_number INTEGER NOT NULL,
 legacy_id TEXT,
 severity TEXT NOT NULL,
 message TEXT NOT NULL,
 source_hash TEXT
);
