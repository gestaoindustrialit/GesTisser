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

function erp_column_exists(PDO $pdo, string $table, string $column): bool
{
    foreach ($pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC) as $info) {
        if ((string) $info['name'] === $column) { return true; }
    }
    return false;
}

function erp_backup_database_once(PDO $pdo)
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

function erp_run_phase1_migrations(PDO $pdo)
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
            'CREATE TABLE IF NOT EXISTS erp_article_materials (id INTEGER PRIMARY KEY AUTOINCREMENT, finished_product_id INTEGER NOT NULL, raw_material_id INTEGER NOT NULL, quantity_per_unit REAL NOT NULL CHECK(quantity_per_unit > 0), waste_percent REAL NOT NULL DEFAULT 0 CHECK(waste_percent >= 0), notes TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE(finished_product_id, raw_material_id), FOREIGN KEY(finished_product_id) REFERENCES erp_finished_products(id) ON DELETE CASCADE, FOREIGN KEY(raw_material_id) REFERENCES erp_raw_materials(id) ON DELETE RESTRICT)',
            'CREATE TABLE IF NOT EXISTS erp_customer_delivery_addresses (id INTEGER PRIMARY KEY AUTOINCREMENT, customer_id INTEGER NOT NULL, label TEXT NOT NULL, address TEXT NOT NULL, postal_code TEXT, city TEXT, country TEXT NOT NULL DEFAULT "Portugal", transporter TEXT NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(customer_id) REFERENCES erp_customers(id) ON DELETE CASCADE)',
            'CREATE TABLE IF NOT EXISTS erp_stock_alert_log (id INTEGER PRIMARY KEY AUTOINCREMENT, raw_material_id INTEGER NOT NULL, available_qty REAL NOT NULL, threshold_qty REAL NOT NULL, recipient_email TEXT NOT NULL, delivery_status TEXT NOT NULL, error_message TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(raw_material_id) REFERENCES erp_raw_materials(id) ON DELETE CASCADE)',
            'CREATE TABLE IF NOT EXISTS erp_product_documents (id INTEGER PRIMARY KEY AUTOINCREMENT, entity_type TEXT NOT NULL, entity_id INTEGER NOT NULL, document_type TEXT NOT NULL, title TEXT NOT NULL, file_url TEXT, version TEXT, author_user_id INTEGER, is_required INTEGER NOT NULL DEFAULT 0, valid_until TEXT, status TEXT NOT NULL DEFAULT "Ativo", notes TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(author_user_id) REFERENCES users(id) ON DELETE SET NULL)',
            'CREATE TABLE IF NOT EXISTS erp_stock_movements (id INTEGER PRIMARY KEY AUTOINCREMENT, movement_number TEXT NOT NULL UNIQUE, movement_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, movement_type TEXT NOT NULL, item_type TEXT NOT NULL CHECK(item_type IN ("raw_material","finished_product")), item_id INTEGER NOT NULL, lot TEXT, roll_id INTEGER, quantity REAL NOT NULL, weight REAL NOT NULL DEFAULT 0, warehouse_from_id INTEGER, location_from_id INTEGER, warehouse_to_id INTEGER, location_to_id INTEGER, unit_cost REAL NOT NULL DEFAULT 0, total_cost REAL NOT NULL DEFAULT 0, source_type TEXT, source_id INTEGER, reversal_of_id INTEGER, reason TEXT, notes TEXT, created_by INTEGER, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL, FOREIGN KEY(warehouse_from_id) REFERENCES erp_warehouses(id) ON DELETE SET NULL, FOREIGN KEY(warehouse_to_id) REFERENCES erp_warehouses(id) ON DELETE SET NULL, FOREIGN KEY(location_from_id) REFERENCES erp_locations(id) ON DELETE SET NULL, FOREIGN KEY(location_to_id) REFERENCES erp_locations(id) ON DELETE SET NULL, FOREIGN KEY(reversal_of_id) REFERENCES erp_stock_movements(id) ON DELETE RESTRICT)',
            'CREATE TABLE IF NOT EXISTS erp_stock_balances (item_type TEXT NOT NULL, item_id INTEGER NOT NULL, warehouse_id INTEGER, location_id INTEGER, lot TEXT, physical_qty REAL NOT NULL DEFAULT 0, reserved_qty REAL NOT NULL DEFAULT 0, blocked_qty REAL NOT NULL DEFAULT 0, ordered_qty REAL NOT NULL DEFAULT 0, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(item_type, item_id, warehouse_id, location_id, lot))'
        ];
        foreach ($sql as $statement) { $pdo->exec($statement); }
        /* Routing is versioned and copied to the OF.  Existing operation/OF tables are
           extended rather than replaced so installations already in production remain valid. */
        $routingSql = [
            'CREATE TABLE IF NOT EXISTS erp_operation_machines (operation_id INTEGER NOT NULL, machine_id INTEGER NOT NULL, is_default INTEGER NOT NULL DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(operation_id,machine_id), FOREIGN KEY(operation_id) REFERENCES erp_operations(id) ON DELETE CASCADE, FOREIGN KEY(machine_id) REFERENCES erp_machines(id) ON DELETE RESTRICT)',
            'CREATE TABLE IF NOT EXISTS erp_operation_documents (id INTEGER PRIMARY KEY AUTOINCREMENT, operation_id INTEGER NOT NULL, title TEXT NOT NULL, file_url TEXT NOT NULL, created_by INTEGER, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(operation_id) REFERENCES erp_operations(id) ON DELETE CASCADE, FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL)',
            'CREATE TABLE IF NOT EXISTS erp_article_routings (id INTEGER PRIMARY KEY AUTOINCREMENT, finished_product_id INTEGER NOT NULL, name TEXT NOT NULL DEFAULT "Routing principal", created_by INTEGER, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(finished_product_id) REFERENCES erp_finished_products(id) ON DELETE CASCADE, FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL)',
            'CREATE TABLE IF NOT EXISTS erp_article_routing_versions (id INTEGER PRIMARY KEY AUTOINCREMENT, routing_id INTEGER NOT NULL, version_no INTEGER NOT NULL, status TEXT NOT NULL DEFAULT "draft" CHECK(status IN ("draft","active","archived")), effective_from TEXT, notes TEXT, based_on_version_id INTEGER, created_by INTEGER, activated_by INTEGER, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, activated_at DATETIME, UNIQUE(routing_id,version_no), FOREIGN KEY(routing_id) REFERENCES erp_article_routings(id) ON DELETE CASCADE, FOREIGN KEY(based_on_version_id) REFERENCES erp_article_routing_versions(id) ON DELETE SET NULL, FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL, FOREIGN KEY(activated_by) REFERENCES users(id) ON DELETE SET NULL)',
            'CREATE TABLE IF NOT EXISTS erp_article_routing_steps (id INTEGER PRIMARY KEY AUTOINCREMENT, routing_version_id INTEGER NOT NULL, operation_id INTEGER NOT NULL, operation_no INTEGER NOT NULL, sort_order INTEGER NOT NULL, work_center_id INTEGER, primary_machine_id INTEGER, operators_count INTEGER NOT NULL DEFAULT 1 CHECK(operators_count>0), setup_time REAL NOT NULL DEFAULT 0 CHECK(setup_time>=0), run_value REAL NOT NULL DEFAULT 0 CHECK(run_value>=0), calculation_unit TEXT NOT NULL DEFAULT "minutes_per_unit", base_quantity REAL NOT NULL DEFAULT 1 CHECK(base_quantity>0), waste_percent REAL NOT NULL DEFAULT 0 CHECK(waste_percent>=0), wait_minutes REAL NOT NULL DEFAULT 0 CHECK(wait_minutes>=0), transfer_minutes REAL NOT NULL DEFAULT 0 CHECK(transfer_minutes>=0), parallel_allowed INTEGER NOT NULL DEFAULT 0, specific_instructions TEXT, quality_points TEXT, confirmation_required INTEGER NOT NULL DEFAULT 1, is_active INTEGER NOT NULL DEFAULT 1, operation_snapshot_json TEXT NOT NULL, UNIQUE(routing_version_id,sort_order), FOREIGN KEY(routing_version_id) REFERENCES erp_article_routing_versions(id) ON DELETE CASCADE, FOREIGN KEY(operation_id) REFERENCES erp_operations(id) ON DELETE RESTRICT, FOREIGN KEY(work_center_id) REFERENCES erp_work_centers(id) ON DELETE SET NULL, FOREIGN KEY(primary_machine_id) REFERENCES erp_machines(id) ON DELETE SET NULL)',
            'CREATE TABLE IF NOT EXISTS erp_routing_step_machines (routing_step_id INTEGER NOT NULL, machine_id INTEGER NOT NULL, PRIMARY KEY(routing_step_id,machine_id), FOREIGN KEY(routing_step_id) REFERENCES erp_article_routing_steps(id) ON DELETE CASCADE, FOREIGN KEY(machine_id) REFERENCES erp_machines(id) ON DELETE RESTRICT)',
            'CREATE TABLE IF NOT EXISTS erp_routing_step_dependencies (routing_step_id INTEGER NOT NULL, predecessor_step_id INTEGER NOT NULL, PRIMARY KEY(routing_step_id,predecessor_step_id), CHECK(routing_step_id<>predecessor_step_id), FOREIGN KEY(routing_step_id) REFERENCES erp_article_routing_steps(id) ON DELETE CASCADE, FOREIGN KEY(predecessor_step_id) REFERENCES erp_article_routing_steps(id) ON DELETE CASCADE)',
            'CREATE TABLE IF NOT EXISTS erp_routing_step_documents (id INTEGER PRIMARY KEY AUTOINCREMENT, routing_step_id INTEGER NOT NULL, title TEXT NOT NULL, file_url TEXT NOT NULL, created_by INTEGER, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(routing_step_id) REFERENCES erp_article_routing_steps(id) ON DELETE CASCADE)',
            'CREATE TABLE IF NOT EXISTS erp_production_order_routing_snapshots (id INTEGER PRIMARY KEY AUTOINCREMENT, production_order_id INTEGER NOT NULL UNIQUE, source_routing_version_id INTEGER, version_no INTEGER NOT NULL, effective_from TEXT, snapshot_json TEXT NOT NULL, total_planned_minutes REAL NOT NULL DEFAULT 0, override_reason TEXT, created_by INTEGER, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(production_order_id) REFERENCES erp_production_orders(id) ON DELETE CASCADE, FOREIGN KEY(source_routing_version_id) REFERENCES erp_article_routing_versions(id) ON DELETE SET NULL, FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL)',
            'CREATE TABLE IF NOT EXISTS erp_operation_execution_operators (time_entry_id INTEGER NOT NULL, user_id INTEGER NOT NULL, PRIMARY KEY(time_entry_id,user_id), FOREIGN KEY(time_entry_id) REFERENCES erp_operation_time_entries(id) ON DELETE CASCADE, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE RESTRICT)',
            'CREATE TABLE IF NOT EXISTS erp_operation_stoppages (id INTEGER PRIMARY KEY AUTOINCREMENT, time_entry_id INTEGER NOT NULL, reason TEXT NOT NULL, started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, ended_at DATETIME, created_by INTEGER, FOREIGN KEY(time_entry_id) REFERENCES erp_operation_time_entries(id) ON DELETE CASCADE)',
            'CREATE TABLE IF NOT EXISTS erp_operation_waste (id INTEGER PRIMARY KEY AUTOINCREMENT, time_entry_id INTEGER NOT NULL, quantity REAL NOT NULL CHECK(quantity>=0), reason TEXT NOT NULL, created_by INTEGER, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(time_entry_id) REFERENCES erp_operation_time_entries(id) ON DELETE CASCADE)',
            'CREATE TABLE IF NOT EXISTS erp_operation_quality_checks (id INTEGER PRIMARY KEY AUTOINCREMENT, production_order_operation_id INTEGER NOT NULL, checkpoint TEXT NOT NULL, result TEXT NOT NULL CHECK(result IN ("pass","fail","na")), notes TEXT, checked_by INTEGER, checked_at DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(production_order_operation_id) REFERENCES erp_production_order_operations(id) ON DELETE CASCADE)',
            'CREATE TABLE IF NOT EXISTS erp_operation_checklist_responses (id INTEGER PRIMARY KEY AUTOINCREMENT, production_order_operation_id INTEGER NOT NULL, time_entry_id INTEGER, checklist_template_id INTEGER NOT NULL, user_id INTEGER NOT NULL, phase TEXT NOT NULL CHECK(phase IN ("start","end","first")), response_json TEXT NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(production_order_operation_id) REFERENCES erp_production_order_operations(id) ON DELETE CASCADE, FOREIGN KEY(time_entry_id) REFERENCES erp_operation_time_entries(id) ON DELETE SET NULL, FOREIGN KEY(checklist_template_id) REFERENCES checklist_templates(id) ON DELETE RESTRICT, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE RESTRICT)',
            'CREATE TABLE IF NOT EXISTS erp_routing_audit (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, action TEXT NOT NULL, entity_type TEXT NOT NULL, entity_id INTEGER NOT NULL, before_json TEXT, after_json TEXT, reason TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL)'
        ];
        foreach ($routingSql as $statement) { $pdo->exec($statement); }
        $operationColumns=['description'=>'TEXT','operation_type'=>'TEXT NOT NULL DEFAULT "production"','default_instructions'=>'TEXT','default_confirmation_required'=>'INTEGER NOT NULL DEFAULT 1','setup_minutes'=>'REAL NOT NULL DEFAULT 0','time_per_unit'=>'REAL NOT NULL DEFAULT 0','time_unit'=>'TEXT NOT NULL DEFAULT "seconds"','production_unit'=>'TEXT NOT NULL DEFAULT "unit"','min_operators'=>'INTEGER NOT NULL DEFAULT 1','requires_good_quantity'=>'INTEGER NOT NULL DEFAULT 1','requires_waste'=>'INTEGER NOT NULL DEFAULT 1','requires_waste_reason'=>'INTEGER NOT NULL DEFAULT 1','requires_quality'=>'INTEGER NOT NULL DEFAULT 0','checklist_template_id'=>'INTEGER REFERENCES checklist_templates(id) ON DELETE SET NULL','checklist_timing'=>'TEXT','notes'=>'TEXT','created_by'=>'INTEGER REFERENCES users(id) ON DELETE SET NULL','updated_by'=>'INTEGER REFERENCES users(id) ON DELETE SET NULL','created_at'=>'DATETIME DEFAULT CURRENT_TIMESTAMP','updated_at'=>'DATETIME DEFAULT CURRENT_TIMESTAMP'];
        foreach($operationColumns as $column=>$definition){if(!erp_column_exists($pdo,'erp_operations',$column))$pdo->exec('ALTER TABLE erp_operations ADD COLUMN '.$column.' '.$definition);}
        $poOperationColumns=['routing_step_id'=>'INTEGER REFERENCES erp_article_routing_steps(id) ON DELETE SET NULL','operation_code'=>'TEXT','operation_name'=>'TEXT','work_center_id'=>'INTEGER REFERENCES erp_work_centers(id) ON DELETE SET NULL','primary_machine_id'=>'INTEGER REFERENCES erp_machines(id) ON DELETE SET NULL','selected_machine_id'=>'INTEGER REFERENCES erp_machines(id) ON DELETE SET NULL','allowed_machine_ids_json'=>'TEXT','operators_count'=>'INTEGER NOT NULL DEFAULT 1','setup_minutes'=>'REAL NOT NULL DEFAULT 0','run_value'=>'REAL NOT NULL DEFAULT 0','calculation_unit'=>'TEXT','base_quantity'=>'REAL NOT NULL DEFAULT 1','waste_percent'=>'REAL NOT NULL DEFAULT 0','wait_minutes'=>'REAL NOT NULL DEFAULT 0','transfer_minutes'=>'REAL NOT NULL DEFAULT 0','parallel_allowed'=>'INTEGER NOT NULL DEFAULT 0','instructions'=>'TEXT','quality_points'=>'TEXT','confirmation_required'=>'INTEGER NOT NULL DEFAULT 1','checklist_template_id'=>'INTEGER REFERENCES checklist_templates(id) ON DELETE SET NULL','checklist_timing'=>'TEXT','snapshot_json'=>'TEXT'];
        foreach($poOperationColumns as $column=>$definition){if(!erp_column_exists($pdo,'erp_production_order_operations',$column))$pdo->exec('ALTER TABLE erp_production_order_operations ADD COLUMN '.$column.' '.$definition);}
        foreach(['selected_machine_id'=>'INTEGER REFERENCES erp_machines(id) ON DELETE SET NULL','status'=>'TEXT NOT NULL DEFAULT "running"','paused_at'=>'DATETIME','pause_seconds'=>'INTEGER NOT NULL DEFAULT 0','corrected_by'=>'INTEGER REFERENCES users(id) ON DELETE SET NULL','corrected_at'=>'DATETIME'] as $column=>$definition){if(!erp_column_exists($pdo,'erp_operation_time_entries',$column))$pdo->exec('ALTER TABLE erp_operation_time_entries ADD COLUMN '.$column.' '.$definition);}
        foreach(['idx_routing_article ON erp_article_routings(finished_product_id)','idx_routing_version_status ON erp_article_routing_versions(routing_id,status,effective_from)','idx_routing_steps_version ON erp_article_routing_steps(routing_version_id,sort_order)','idx_po_operations_order ON erp_production_order_operations(production_order_id,sequence_no)','idx_execution_open ON erp_operation_time_entries(production_order_operation_id,ended_at)'] as $index){$pdo->exec('CREATE INDEX IF NOT EXISTS '.$index);}
        $customerColumns = [
            'country_prefix'=>'TEXT', 'mobile'=>'TEXT', 'address_2'=>'TEXT', 'city'=>'TEXT',
            'postal_code'=>'TEXT', 'fax'=>'TEXT', 'contact_name'=>'TEXT', 'salesperson'=>'TEXT',
            'notes'=>'TEXT', 'balance'=>'REAL NOT NULL DEFAULT 0', 'credit_limit'=>'REAL NOT NULL DEFAULT 0',
            'updated_at'=>'DATETIME DEFAULT CURRENT_TIMESTAMP'
        ];
        foreach ($customerColumns as $column=>$definition) {
            if (!erp_column_exists($pdo,'erp_customers',$column)) { $pdo->exec('ALTER TABLE erp_customers ADD COLUMN '.$column.' '.$definition); }
        }
        /* Article master data owns every stable value used by a technical sheet. Order-only
           values stay on the OF and the generated snapshot, preserving historical documents. */
        $articleColumns = [
            'material' => 'TEXT', 'bag_color' => 'TEXT', 'width_tolerance' => 'TEXT',
            'length_tolerance' => 'TEXT', 'front_colors' => 'TEXT', 'back_colors' => 'TEXT',
            'pallet_dimensions' => 'TEXT', 'pallet_lid' => 'TEXT', 'pallet_straps' => 'INTEGER',
            'pallet_film' => 'TEXT', 'microperforation' => 'INTEGER NOT NULL DEFAULT 0',
            'analysis_grammage' => 'TEXT', 'analysis_total_weight' => 'TEXT',
            'analysis_apparent_width' => 'TEXT', 'analysis_gusset_width' => 'TEXT',
            'analysis_bag_height' => 'TEXT', 'analysis_break_height' => 'TEXT',
            'analysis_break_length' => 'TEXT', 'analysis_seam_strength' => 'TEXT',
            'analysis_static_friction' => 'TEXT', 'analysis_dynamic_friction' => 'TEXT',
            'analysis_air_permeability' => 'TEXT'
        ];
        foreach ($articleColumns as $column => $definition) {
            if (!erp_column_exists($pdo, 'erp_finished_products', $column)) {
                $pdo->exec('ALTER TABLE erp_finished_products ADD COLUMN ' . $column . ' ' . $definition);
            }
        }
        $rawMaterialColumns = [
            'standard_warehouse_id' => 'INTEGER REFERENCES erp_warehouses(id) ON DELETE SET NULL',
            'alert_email' => 'TEXT', 'alert_enabled' => 'INTEGER NOT NULL DEFAULT 1'
        ];
        foreach ($rawMaterialColumns as $column => $definition) {
            if (!erp_column_exists($pdo, 'erp_raw_materials', $column)) {
                $pdo->exec('ALTER TABLE erp_raw_materials ADD COLUMN ' . $column . ' ' . $definition);
            }
        }
        if (!erp_column_exists($pdo, 'erp_production_orders', 'finished_product_id')) {
            $pdo->exec('ALTER TABLE erp_production_orders ADD COLUMN finished_product_id INTEGER REFERENCES erp_finished_products(id)');
        }
        foreach (['delivery_address_id'=>'INTEGER REFERENCES erp_customer_delivery_addresses(id) ON DELETE SET NULL', 'delivery_address_snapshot'=>'TEXT', 'transporter'=>'TEXT'] as $column => $definition) {
            if (!erp_column_exists($pdo, 'erp_production_orders', $column)) {
                $pdo->exec('ALTER TABLE erp_production_orders ADD COLUMN ' . $column . ' ' . $definition);
            }
        }
        $pdo->exec('CREATE TABLE IF NOT EXISTS erp_technical_sheets (id INTEGER PRIMARY KEY AUTOINCREMENT, production_order_id INTEGER NOT NULL UNIQUE, finished_product_id INTEGER NOT NULL, snapshot_json TEXT NOT NULL, created_by INTEGER, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(production_order_id) REFERENCES erp_production_orders(id) ON DELETE CASCADE, FOREIGN KEY(finished_product_id) REFERENCES erp_finished_products(id), FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_erp_stock_movements_item ON erp_stock_movements(item_type, item_id, movement_date)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_erp_raw_materials_status ON erp_raw_materials(status, code)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_erp_finished_products_customer ON erp_finished_products(customer_id, status)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_erp_article_materials_article ON erp_article_materials(finished_product_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_erp_customer_delivery_addresses_customer ON erp_customer_delivery_addresses(customer_id, id)');

        $perm = $pdo->prepare('INSERT OR IGNORE INTO erp_permissions(code,label,description) VALUES (?,?,?)');
        foreach (erp_default_permissions() as $code => $label) { $perm->execute([$code, $label, $label]); }
        $rolePermission=$pdo->prepare('INSERT OR IGNORE INTO erp_role_permissions(profile,permission_code) VALUES (?,?)');
        foreach(['Produção','Chefias'] as $role){foreach(['erp.operations.view','erp.routings.view','erp.shopfloor.execute'] as $permission)$rolePermission->execute([$role,$permission]);}
        foreach(['erp.operations.manage','erp.routings.edit','erp.routings.activate','erp.work_order_routing.edit','erp.execution.correct'] as $permission)$rolePermission->execute(['Chefias',$permission]);
        $seq = $pdo->prepare('INSERT OR IGNORE INTO erp_number_sequences(code,prefix,next_number,padding) VALUES (?,?,?,?)');
        foreach ([['stock_movement','MOV-',1,6],['raw_material','MP-',1,5],['finished_product','PA-',1,5],['customer','CLI-',1,4],['supplier','FOR-',1,4],['work_order','OF-',1,5]] as $s) { $seq->execute($s); }
        $set = $pdo->prepare('INSERT OR IGNORE INTO erp_settings(key,value) VALUES (?,?)');
        $set->execute(['allow_negative_stock','0']);
        $set->execute(['raw_material_code_pattern','{tipo}{caracteristica}{largura}{gramagem}{seq}']);
        $set->execute(['labor_hourly_rate','0.00']);

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
        'erp.view'=>'Ver ERP','erp.master_data'=>'Gerir dados mestre','erp.customers'=>'Gerir clientes','erp.suppliers'=>'Gerir fornecedores','erp.purchases'=>'Gerir compras','erp.purchase_approve'=>'Aprovar compras','erp.receipts'=>'Registar receções','erp.sales'=>'Gerir vendas','erp.confirm_orders'=>'Confirmar encomendas','erp.work_orders_create'=>'Criar OF','erp.work_orders_release'=>'Libertar OF','erp.planning'=>'Planear produção','erp.production_register'=>'Registar produção','erp.consumptions'=>'Registar consumos','erp.stock_move'=>'Movimentar stock','erp.stock_adjust'=>'Ajustar stock','erp.inventory_approve'=>'Aprovar inventários','erp.shipments_prepare'=>'Preparar expedições','erp.shipments_confirm'=>'Confirmar expedições','erp.quality'=>'Gerir qualidade','erp.costs_view'=>'Consultar custos','erp.costs_edit'=>'Alterar custos','erp.period_close'=>'Fechar períodos','erp.reports_export'=>'Exportar relatórios','erp.documents_cancel'=>'Anular documentos',
        'erp.operations.view'=>'Consultar operações','erp.operations.manage'=>'Gerir operações','erp.routings.view'=>'Consultar routings','erp.routings.edit'=>'Criar/editar routings','erp.routings.activate'=>'Ativar versões de routing','erp.work_order_routing.edit'=>'Alterar routing de uma OF','erp.shopfloor.execute'=>'Executar operações no Shopfloor','erp.execution.correct'=>'Corrigir registos de execução'
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

function erp_audit(PDO $pdo, $userId, string $action, string $entity, $entityId, array $oldValues = [], array $newValues = [], $reason = null)
{
    $stmt = $pdo->prepare('INSERT INTO erp_audit_log(user_id, action, entity, entity_id, old_values_json, new_values_json, ip_address, reason) VALUES (?,?,?,?,?,?,?,?)');
    $stmt->execute([$userId, $action, $entity, $entityId, json_encode($oldValues, JSON_UNESCAPED_UNICODE), json_encode($newValues, JSON_UNESCAPED_UNICODE), (string)($_SERVER['REMOTE_ADDR'] ?? ''), $reason]);
}
