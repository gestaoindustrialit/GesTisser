-- Additive schema. No operational tables or triggers are changed.
CREATE TABLE IF NOT EXISTS erp_legacy_of_batches (
 id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL REFERENCES users(id),
 batch_hash TEXT NOT NULL, status TEXT NOT NULL CHECK(status IN ('success','failed')),
 backup_path TEXT NOT NULL, backup_sha256 TEXT NOT NULL, report_json TEXT NOT NULL,
 imported_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS erp_legacy_production_orders (
 id INTEGER PRIMARY KEY, source_system TEXT NOT NULL CHECK(source_system='Bobinas'), legacy_id TEXT NOT NULL,
 legacy_of_number TEXT NOT NULL, legacy_order_id TEXT, legacy_order_number TEXT,
 legacy_customer_code TEXT, legacy_product_code TEXT,
 gestisser_customer_id INTEGER REFERENCES erp_customers(id),
 gestisser_product_id INTEGER REFERENCES erp_finished_products(id),
 customer_resolution TEXT NOT NULL CHECK(customer_resolution IN ('resolved','pending')),
 product_resolution TEXT NOT NULL CHECK(product_resolution IN ('resolved','pending')),
 planned_quantity REAL CHECK(planned_quantity>=0), produced_quantity REAL CHECK(produced_quantity>=0), quantity_unit TEXT,
 created_at_legacy TEXT, started_at_legacy TEXT, finished_at_legacy TEXT, legacy_status TEXT, observations TEXT,
 source_hash TEXT NOT NULL, original_json TEXT NOT NULL,
 imported_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, import_batch_id INTEGER NOT NULL REFERENCES erp_legacy_of_batches(id),
 UNIQUE(source_system,legacy_id)
);
CREATE INDEX IF NOT EXISTS idx_legacy_of_customer_date ON erp_legacy_production_orders(gestisser_customer_id,created_at_legacy,id);
CREATE INDEX IF NOT EXISTS idx_legacy_of_product_date ON erp_legacy_production_orders(gestisser_product_id,created_at_legacy,id);
CREATE INDEX IF NOT EXISTS idx_legacy_of_date ON erp_legacy_production_orders(created_at_legacy,id);
CREATE INDEX IF NOT EXISTS idx_legacy_of_number ON erp_legacy_production_orders(legacy_of_number);
CREATE INDEX IF NOT EXISTS idx_legacy_of_pending ON erp_legacy_production_orders(customer_resolution,product_resolution,id);
CREATE TABLE IF NOT EXISTS erp_legacy_of_cost_summary (
 legacy_of_id INTEGER PRIMARY KEY REFERENCES erp_legacy_production_orders(id),
 total_production_time REAL, setup_time REAL, execution_time REAL, time_unit TEXT,
 produced_quantity REAL, labor_cost REAL, material_cost REAL, other_cost REAL, total_cost REAL, unit_cost REAL,
 currency TEXT, cost_type TEXT CHECK(cost_type IN ('real','estimated')), origin TEXT NOT NULL, calculation_method TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS erp_legacy_of_cost_details (
 id INTEGER PRIMARY KEY, legacy_of_id INTEGER NOT NULL REFERENCES erp_legacy_production_orders(id),
 source_system TEXT NOT NULL CHECK(source_system='Bobinas'), legacy_id TEXT NOT NULL,
 operation_code TEXT, operation_name TEXT, sequence_no INTEGER, registered_time REAL CHECK(registered_time>=0),
 planned_time REAL CHECK(planned_time>=0), processed_quantity REAL CHECK(processed_quantity>=0),
 registered_cost REAL CHECK(registered_cost>=0), time_unit TEXT, quantity_unit TEXT, currency TEXT,
 cost_type TEXT CHECK(cost_type IN ('real','estimated')), origin TEXT NOT NULL,
 started_at_legacy TEXT, finished_at_legacy TEXT, legacy_status TEXT,
 source_hash TEXT NOT NULL, original_json TEXT NOT NULL, import_batch_id INTEGER NOT NULL REFERENCES erp_legacy_of_batches(id),
 UNIQUE(source_system,legacy_id)
);
CREATE INDEX IF NOT EXISTS idx_legacy_of_detail_parent ON erp_legacy_of_cost_details(legacy_of_id,sequence_no,id);
CREATE TABLE IF NOT EXISTS erp_legacy_of_audit (
 id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL REFERENCES users(id), action TEXT NOT NULL,
 legacy_of_id INTEGER REFERENCES erp_legacy_production_orders(id), import_batch_id INTEGER REFERENCES erp_legacy_of_batches(id),
 before_json TEXT, after_json TEXT, evidence TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
