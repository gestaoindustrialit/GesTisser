<?php
declare(strict_types=1);

if (defined('GESTISSER_ERP_MIGRATIONS_LOADED')) {
    return;
}
define('GESTISSER_ERP_MIGRATIONS_LOADED', true);

function erp_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=? LIMIT 1");
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}

function erp_backup_database_once(PDO $pdo): ?string
{
    static $backupPath = null;
    if ($backupPath !== null) {
        return $backupPath;
    }
    $dbPath = __DIR__ . '/database.sqlite';
    if (!is_file($dbPath)) {
        return null;
    }
    $dir = __DIR__ . '/backups';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $backupPath = $dir . '/erp_phase1_' . date('Ymd_His') . '.sqlite';
    @copy($dbPath, $backupPath);
    return $backupPath;
}

function erp_run_phase1_migrations(PDO $pdo): void
{
    static $ran = false;
    if ($ran) {
        return;
    }
    $ran = true;

    $needsBackup = !erp_table_exists($pdo, 'erp_stock_movements') || !erp_table_exists($pdo, 'erp_raw_materials');
    if ($needsBackup) {
        erp_backup_database_once($pdo);
    }

    $pdo->beginTransaction();
    try {
        $sql = [
            'CREATE TABLE IF NOT EXISTS erp_permissions (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT NOT NULL UNIQUE, label TEXT NOT NULL, description TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)',
            'CREATE TABLE IF NOT EXISTS erp_role_permissions (profile TEXT NOT NULL, permission_code TEXT NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(profile, permission_code), FOREIGN KEY(permission_code) REFERENCES erp_permissions(code) ON DELETE CASCADE)',
            'CREATE TABLE IF NOT EXISTS erp_user_permissions (user_id INTEGER NOT NULL, permission_code TEXT NOT NULL, is_allowed INTEGER NOT NULL DEFAULT 1, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(user_id, permission_code), FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE, FOREIGN KEY(permission_code) REFERENCES erp_permissions(code) ON DELETE CASCADE)',
            'CREATE TABLE IF NOT EXISTS erp_audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, action TEXT NOT NULL, entity TEXT NOT NULL, entity_id INTEGER, old_values_json TEXT, new_values_json TEXT, ip_address TEXT, reason TEXT, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL)',
            'CREATE TABLE IF NOT EXISTS erp_number_sequences (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT NOT NULL UNIQUE, prefix TEXT NOT NULL, next_number INTEGER NOT NULL DEFAULT 1, padding INTEGER NOT NULL DEFAULT 5, suffix TEXT, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)',
            'CREATE TABLE IF NOT EXISTS erp_settings (key TEXT PRIMARY KEY, value TEXT, updated_by INTEGER, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(updated_by) REFERENCES users(id) ON DELETE SET NULL)',
            'CREATE TABLE IF NOT EXISTS erp_material_features (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT NOT NULL UNIQUE, description TEXT NOT NULL, material_type_id INTEGER, is_active INTEGER NOT NULL DEFAULT 1, notes TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(material_type_id) REFERENCES erp_material_types(id) ON DELETE SET NULL)',
            'CREATE TABLE IF NOT EXISTS erp_inks (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT NOT NULL UNIQUE, description TEXT NOT NULL, pantone TEXT, is_water_based INTEGER NOT NULL DEFAULT 0, last_price REAL NOT NULL DEFAULT 0, preferred_supplier_id INTEGER, notes TEXT, is_active INTEGER NOT NULL DEFAULT 1, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(preferred_supplier_id) REFERENCES erp_suppliers(id) ON DELETE SET NULL)',
            'CREATE TABLE IF NOT EXISTS erp_location_types (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT NOT NULL UNIQUE, description TEXT NOT NULL, is_active INTEGER NOT NULL DEFAULT 1)',
            'CREATE TABLE IF NOT EXISTS erp_locations (id INTEGER PRIMARY KEY AUTOINCREMENT, warehouse_id INTEGER NOT NULL, code TEXT NOT NULL, description TEXT, location_type_id INTEGER, zone TEXT, aisle TEXT, rack TEXT, shelf TEXT, is_active INTEGER NOT NULL DEFAULT 1, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE(warehouse_id, code), FOREIGN KEY(warehouse_id) REFERENCES erp_warehouses(id) ON DELETE CASCADE, FOREIGN KEY(location_type_id) REFERENCES erp_location_types(id) ON DELETE SET NULL)',
            'CREATE TABLE IF NOT EXISTS erp_raw_materials (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT NOT NULL UNIQUE, description TEXT NOT NULL, material_type_id INTEGER, material_feature_id INTEGER, width REAL, grammage REAL, thickness REAL, color_id INTEGER, primary_unit_id INTEGER, secondary_unit_id INTEGER, weight_per_unit REAL, length REAL, min_stock REAL NOT NULL DEFAULT 0, max_stock REAL NOT NULL DEFAULT 0, reorder_point REAL NOT NULL DEFAULT 0, lead_time_days INTEGER NOT NULL DEFAULT 0, preferred_supplier_id INTEGER, average_price REAL NOT NULL DEFAULT 0, last_price REAL NOT NULL DEFAULT 0, standard_price REAL NOT NULL DEFAULT 0, preferred_location_id INTEGER, lot_controlled INTEGER NOT NULL DEFAULT 1, roll_controlled INTEGER NOT NULL DEFAULT 1, allow_partial_consumption INTEGER NOT NULL DEFAULT 1, status TEXT NOT NULL DEFAULT "Ativo", notes TEXT, created_by INTEGER, updated_by INTEGER, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(material_type_id) REFERENCES erp_material_types(id) ON DELETE SET NULL, FOREIGN KEY(material_feature_id) REFERENCES erp_material_features(id) ON DELETE SET NULL, FOREIGN KEY(color_id) REFERENCES erp_colors(id) ON DELETE SET NULL, FOREIGN KEY(primary_unit_id) REFERENCES erp_units(id) ON DELETE SET NULL, FOREIGN KEY(secondary_unit_id) REFERENCES erp_units(id) ON DELETE SET NULL, FOREIGN KEY(preferred_supplier_id) REFERENCES erp_suppliers(id) ON DELETE SET NULL, FOREIGN KEY(preferred_location_id) REFERENCES erp_locations(id) ON DELETE SET NULL, FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL, FOREIGN KEY(updated_by) REFERENCES users(id) ON DELETE SET NULL)',
            'CREATE TABLE IF NOT EXISTS erp_finished_products (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT NOT NULL UNIQUE, customer_product_code TEXT, customer_id INTEGER, product_type_id INTEGER, material_type_id INTEGER, material_feature_id INTEGER, width REAL, length REAL, grammage REAL, colors_per_face INTEGER NOT NULL DEFAULT 0, description TEXT NOT NULL, unit_id INTEGER, min_stock REAL NOT NULL DEFAULT 0, max_stock REAL NOT NULL DEFAULT 0, proof_reference TEXT, artwork_file TEXT, proof_status TEXT DEFAULT "Pendente", printer_roll_measure TEXT, composition TEXT, theoretical_weight REAL, sale_price REAL NOT NULL DEFAULT 0, standard_cost REAL NOT NULL DEFAULT 0, standard_margin REAL NOT NULL DEFAULT 0, has_handle INTEGER NOT NULL DEFAULT 0, has_holes INTEGER NOT NULL DEFAULT 0, has_gusset INTEGER NOT NULL DEFAULT 0, centered_gusset INTEGER NOT NULL DEFAULT 0, gusset_length REAL, anti_slip_ink INTEGER NOT NULL DEFAULT 0, anti_slip_mesh INTEGER NOT NULL DEFAULT 0, thread_color TEXT, perforation_type TEXT, seam_type TEXT, lot_identification_rule TEXT, status TEXT NOT NULL DEFAULT "Ativo", notes TEXT, created_by INTEGER, updated_by INTEGER, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(customer_id) REFERENCES erp_customers(id) ON DELETE SET NULL, FOREIGN KEY(product_type_id) REFERENCES erp_product_types(id) ON DELETE SET NULL, FOREIGN KEY(material_type_id) REFERENCES erp_material_types(id) ON DELETE SET NULL, FOREIGN KEY(material_feature_id) REFERENCES erp_material_features(id) ON DELETE SET NULL, FOREIGN KEY(unit_id) REFERENCES erp_units(id) ON DELETE SET NULL)',
            'CREATE TABLE IF NOT EXISTS erp_product_colors (id INTEGER PRIMARY KEY AUTOINCREMENT, finished_product_id INTEGER NOT NULL, color_id INTEGER, color_order INTEGER NOT NULL DEFAULT 1, face TEXT, pantone TEXT, ink_type TEXT, planned_quantity REAL, actual_quantity REAL, notes TEXT, FOREIGN KEY(finished_product_id) REFERENCES erp_finished_products(id) ON DELETE CASCADE, FOREIGN KEY(color_id) REFERENCES erp_colors(id) ON DELETE SET NULL)',
            'CREATE TABLE IF NOT EXISTS erp_product_features (id INTEGER PRIMARY KEY AUTOINCREMENT, finished_product_id INTEGER NOT NULL, feature_key TEXT NOT NULL, feature_value TEXT, FOREIGN KEY(finished_product_id) REFERENCES erp_finished_products(id) ON DELETE CASCADE)',
            'CREATE TABLE IF NOT EXISTS erp_product_documents (id INTEGER PRIMARY KEY AUTOINCREMENT, entity_type TEXT NOT NULL, entity_id INTEGER NOT NULL, document_type TEXT NOT NULL, title TEXT NOT NULL, file_url TEXT, version TEXT, author_user_id INTEGER, is_required INTEGER NOT NULL DEFAULT 0, valid_until TEXT, status TEXT NOT NULL DEFAULT "Ativo", notes TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(author_user_id) REFERENCES users(id) ON DELETE SET NULL)',
            'CREATE TABLE IF NOT EXISTS erp_stock_movements (id INTEGER PRIMARY KEY AUTOINCREMENT, movement_number TEXT NOT NULL UNIQUE, movement_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, movement_type TEXT NOT NULL, item_type TEXT NOT NULL CHECK(item_type IN ("raw_material","finished_product")), item_id INTEGER NOT NULL, lot TEXT, roll_id INTEGER, quantity REAL NOT NULL, weight REAL NOT NULL DEFAULT 0, warehouse_from_id INTEGER, location_from_id INTEGER, warehouse_to_id INTEGER, location_to_id INTEGER, unit_cost REAL NOT NULL DEFAULT 0, total_cost REAL NOT NULL DEFAULT 0, source_type TEXT, source_id INTEGER, reversal_of_id INTEGER, reason TEXT, notes TEXT, created_by INTEGER, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL, FOREIGN KEY(warehouse_from_id) REFERENCES erp_warehouses(id) ON DELETE SET NULL, FOREIGN KEY(warehouse_to_id) REFERENCES erp_warehouses(id) ON DELETE SET NULL, FOREIGN KEY(location_from_id) REFERENCES erp_locations(id) ON DELETE SET NULL, FOREIGN KEY(location_to_id) REFERENCES erp_locations(id) ON DELETE SET NULL, FOREIGN KEY(reversal_of_id) REFERENCES erp_stock_movements(id) ON DELETE RESTRICT)',
            'CREATE TABLE IF NOT EXISTS erp_stock_balances (item_type TEXT NOT NULL, item_id INTEGER NOT NULL, warehouse_id INTEGER, location_id INTEGER, lot TEXT, physical_qty REAL NOT NULL DEFAULT 0, reserved_qty REAL NOT NULL DEFAULT 0, blocked_qty REAL NOT NULL DEFAULT 0, ordered_qty REAL NOT NULL DEFAULT 0, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(item_type, item_id, warehouse_id, location_id, lot))'
        ];
        foreach ($sql as $statement) { $pdo->exec($statement); }
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_erp_stock_movements_item ON erp_stock_movements(item_type, item_id, movement_date)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_erp_raw_materials_status ON erp_raw_materials(status, code)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_erp_finished_products_customer ON erp_finished_products(customer_id, status)');

        $perm = $pdo->prepare('INSERT OR IGNORE INTO erp_permissions(code,label,description) VALUES (?,?,?)');
        foreach (erp_default_permissions() as $code => $label) { $perm->execute([$code, $label, $label]); }
        $seq = $pdo->prepare('INSERT OR IGNORE INTO erp_number_sequences(code,prefix,next_number,padding) VALUES (?,?,?,?)');
        foreach ([['stock_movement','MOV-',1,6],['raw_material','MP-',1,5],['finished_product','PA-',1,5],['customer','CLI-',1,4],['supplier','FOR-',1,4],['work_order','OF-',1,5]] as $s) { $seq->execute($s); }
        $set = $pdo->prepare('INSERT OR IGNORE INTO erp_settings(key,value) VALUES (?,?)');
        $set->execute(['allow_negative_stock','0']);
        $set->execute(['raw_material_code_pattern','{tipo}{caracteristica}{largura}{gramagem}{seq}']);

        foreach ([['BOB','Bobina'],['PAL','Palete'],['PRD','Produção'],['EXP','Expedição']] as $lt) { $pdo->prepare('INSERT OR IGNORE INTO erp_location_types(code,description) VALUES (?,?)')->execute($lt); }
        foreach ([['BL','Branco laminado'],['BNL','Branco não laminado'],['TL','Transparente laminado'],['TNL','Transparente não laminado'],['R30','R30'],['R50','R50']] as $mf) { $pdo->prepare('INSERT OR IGNORE INTO erp_material_features(code,description) VALUES (?,?)')->execute($mf); }
        $pdo->exec('INSERT OR IGNORE INTO erp_locations(warehouse_id, code, description) SELECT id, "GERAL", "Localização geral" FROM erp_warehouses');
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function erp_default_permissions(): array
{
    return [
        'erp.view'=>'Ver ERP','erp.master_data'=>'Gerir dados mestre','erp.customers'=>'Gerir clientes','erp.suppliers'=>'Gerir fornecedores','erp.purchases'=>'Gerir compras','erp.purchase_approve'=>'Aprovar compras','erp.receipts'=>'Registar receções','erp.sales'=>'Gerir vendas','erp.confirm_orders'=>'Confirmar encomendas','erp.work_orders_create'=>'Criar OF','erp.work_orders_release'=>'Libertar OF','erp.planning'=>'Planear produção','erp.production_register'=>'Registar produção','erp.consumptions'=>'Registar consumos','erp.stock_move'=>'Movimentar stock','erp.stock_adjust'=>'Ajustar stock','erp.inventory_approve'=>'Aprovar inventários','erp.shipments_prepare'=>'Preparar expedições','erp.shipments_confirm'=>'Confirmar expedições','erp.quality'=>'Gerir qualidade','erp.costs_view'=>'Consultar custos','erp.costs_edit'=>'Alterar custos','erp.period_close'=>'Fechar períodos','erp.reports_export'=>'Exportar relatórios','erp.documents_cancel'=>'Anular documentos'
    ];
}

function erp_user_can(PDO $pdo, array $user, string $permission): bool
{
    if ((int)($user['is_admin'] ?? 0) === 1) { return true; }
    $profile = (string)($user['access_profile'] ?? '');
    if (in_array($profile, ['Produção','Chefias','RH'], true) && $permission === 'erp.view') { return true; }
    $stmt = $pdo->prepare('SELECT is_allowed FROM erp_user_permissions WHERE user_id=? AND permission_code=? LIMIT 1');
    $stmt->execute([(int)$user['id'], $permission]);
    $specific = $stmt->fetchColumn();
    if ($specific !== false) { return (int)$specific === 1; }
    $stmt = $pdo->prepare('SELECT 1 FROM erp_role_permissions WHERE profile=? AND permission_code=? LIMIT 1');
    $stmt->execute([$profile, $permission]);
    return (bool)$stmt->fetchColumn();
}

function erp_audit(PDO $pdo, ?int $userId, string $action, string $entity, ?int $entityId, array $oldValues = [], array $newValues = [], ?string $reason = null): void
{
    $stmt = $pdo->prepare('INSERT INTO erp_audit_log(user_id, action, entity, entity_id, old_values_json, new_values_json, ip_address, reason) VALUES (?,?,?,?,?,?,?,?)');
    $stmt->execute([$userId, $action, $entity, $entityId, json_encode($oldValues, JSON_UNESCAPED_UNICODE), json_encode($newValues, JSON_UNESCAPED_UNICODE), (string)($_SERVER['REMOTE_ADDR'] ?? ''), $reason]);
}
