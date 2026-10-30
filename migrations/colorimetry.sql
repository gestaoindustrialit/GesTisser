-- Additive, repeatable PHP 7.0 / SQLite module. No recipe seed data.
CREATE TABLE IF NOT EXISTS erp_ink_color_details (
 color_id INTEGER PRIMARY KEY REFERENCES erp_colors(id) ON DELETE RESTRICT,
 customer_id INTEGER REFERENCES erp_customers(id), article_id INTEGER REFERENCES erp_finished_products(id),
 ink_type_id INTEGER REFERENCES erp_ink_types(id), substrate TEXT, finish TEXT, hex TEXT,
 notes TEXT, status TEXT NOT NULL DEFAULT 'Em desenvolvimento', created_by INTEGER REFERENCES users(id), created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS erp_ink_formulas (
 id INTEGER PRIMARY KEY AUTOINCREMENT, color_id INTEGER NOT NULL REFERENCES erp_colors(id) ON DELETE RESTRICT,
 family TEXT NOT NULL, version INTEGER NOT NULL, status TEXT NOT NULL DEFAULT 'Em desenvolvimento',
 substrate TEXT, ink_type_id INTEGER REFERENCES erp_ink_types(id), notes TEXT, components_json TEXT NOT NULL,
 created_by INTEGER NOT NULL REFERENCES users(id), created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 approved_by INTEGER REFERENCES users(id), approved_at TEXT, UNIQUE(family,version)
);
CREATE TABLE IF NOT EXISTS erp_ink_mixes (
 id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT NOT NULL UNIQUE, formula_id INTEGER NOT NULL REFERENCES erp_ink_formulas(id),
 snapshot_json TEXT NOT NULL, planned_mg INTEGER NOT NULL CHECK(planned_mg>0), actual_mg INTEGER NOT NULL CHECK(actual_mg>0),
 notes TEXT, status TEXT NOT NULL DEFAULT 'Em avaliação', created_by INTEGER NOT NULL REFERENCES users(id), created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS erp_ink_containers (
 id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT NOT NULL UNIQUE, mix_id INTEGER NOT NULL REFERENCES erp_ink_mixes(id),
 lot TEXT NOT NULL, initial_mg INTEGER NOT NULL CHECK(initial_mg>0), available_mg INTEGER NOT NULL CHECK(available_mg>=0),
 composition_json TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'Bloqueado', location TEXT, warehouse_id INTEGER REFERENCES erp_warehouses(id),
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS erp_ink_events (
 id INTEGER PRIMARY KEY AUTOINCREMENT, action TEXT NOT NULL, entity TEXT NOT NULL, entity_id INTEGER NOT NULL,
 container_id INTEGER REFERENCES erp_ink_containers(id), production_order_id INTEGER REFERENCES erp_production_orders(id) ON DELETE RESTRICT,
 delta_mg INTEGER NOT NULL DEFAULT 0, payload_json TEXT NOT NULL, user_id INTEGER NOT NULL REFERENCES users(id), created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS erp_ink_requests (
 user_id INTEGER NOT NULL REFERENCES users(id), request_key TEXT NOT NULL, fingerprint TEXT NOT NULL, result_json TEXT NOT NULL,
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(user_id,request_key)
);
CREATE TABLE IF NOT EXISTS erp_ink_formula_attachments (
 id INTEGER PRIMARY KEY AUTOINCREMENT, formula_id INTEGER NOT NULL REFERENCES erp_ink_formulas(id) ON DELETE RESTRICT,
 name TEXT NOT NULL, mime TEXT NOT NULL, content BLOB NOT NULL, created_by INTEGER NOT NULL REFERENCES users(id), created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TRIGGER IF NOT EXISTS ink_attachment_no_update BEFORE UPDATE ON erp_ink_formula_attachments BEGIN SELECT RAISE(ABORT,'Anexo imutável'); END;
CREATE TRIGGER IF NOT EXISTS ink_attachment_no_delete BEFORE DELETE ON erp_ink_formula_attachments BEGIN SELECT RAISE(ABORT,'Anexo permanente'); END;
CREATE TABLE IF NOT EXISTS erp_ink_schema_migrations(version TEXT PRIMARY KEY, applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
CREATE INDEX IF NOT EXISTS idx_ink_formula_color ON erp_ink_formulas(color_id,status);
CREATE INDEX IF NOT EXISTS idx_ink_container_mix ON erp_ink_containers(mix_id,lot,status);
CREATE INDEX IF NOT EXISTS idx_ink_events_search ON erp_ink_events(container_id,production_order_id,user_id,created_at);
CREATE TRIGGER IF NOT EXISTS ink_events_no_update BEFORE UPDATE ON erp_ink_events BEGIN SELECT RAISE(ABORT,'Histórico imutável'); END;
CREATE TRIGGER IF NOT EXISTS ink_events_no_delete BEFORE DELETE ON erp_ink_events BEGIN SELECT RAISE(ABORT,'Histórico permanente'); END;
CREATE TRIGGER IF NOT EXISTS ink_mix_no_update BEFORE UPDATE ON erp_ink_mixes BEGIN SELECT RAISE(ABORT,'Preparação imutável'); END;
CREATE TRIGGER IF NOT EXISTS ink_mix_no_delete BEFORE DELETE ON erp_ink_mixes BEGIN SELECT RAISE(ABORT,'Preparação permanente'); END;
CREATE TRIGGER IF NOT EXISTS ink_container_identity BEFORE UPDATE ON erp_ink_containers WHEN NEW.code<>OLD.code OR NEW.mix_id<>OLD.mix_id OR NEW.lot<>OLD.lot OR NEW.initial_mg<>OLD.initial_mg OR NEW.created_at<>OLD.created_at BEGIN SELECT RAISE(ABORT,'Identidade de recipiente imutável'); END;
CREATE TRIGGER IF NOT EXISTS ink_container_no_delete BEFORE DELETE ON erp_ink_containers BEGIN SELECT RAISE(ABORT,'Recipiente permanente'); END;
CREATE TRIGGER IF NOT EXISTS ink_formula_no_edit BEFORE UPDATE ON erp_ink_formulas WHEN OLD.approved_at IS NOT NULL AND (NEW.components_json<>OLD.components_json OR NEW.color_id<>OLD.color_id OR NEW.version<>OLD.version OR NEW.family<>OLD.family OR NEW.notes IS NOT OLD.notes OR NEW.substrate IS NOT OLD.substrate OR NEW.ink_type_id IS NOT OLD.ink_type_id OR NEW.approved_at IS NOT OLD.approved_at OR NEW.approved_by IS NOT OLD.approved_by) BEGIN SELECT RAISE(ABORT,'Crie uma nova versão'); END;
CREATE TRIGGER IF NOT EXISTS ink_formula_no_delete BEFORE DELETE ON erp_ink_formulas BEGIN SELECT RAISE(ABORT,'Fórmula permanente'); END;
INSERT OR IGNORE INTO erp_permissions(code,label,description) VALUES ('ink.prepare','Colorista','Consultar, preparar, corrigir, consumir e imprimir tintas'),('ink.formula.create','Criar fórmulas de tinta','Criar cores e versões em desenvolvimento'),('ink.formula.approve','Aprovar fórmulas de tinta','Validar ou desativar fórmulas');
INSERT OR IGNORE INTO erp_ink_schema_migrations(version) VALUES ('2026102901');
