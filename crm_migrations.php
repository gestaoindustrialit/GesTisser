<?php
declare(strict_types=1);

function crm_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}

function crm_run_migrations(PDO $pdo)
{
    static $ran = false;
    if ($ran) return;
    $ran = true;
    $sql = [
        'CREATE TABLE IF NOT EXISTS crm_user_settings (user_id INTEGER PRIMARY KEY, leads_visibility TEXT NOT NULL DEFAULT "private" CHECK(leads_visibility IN ("private","shared")), projects_visibility TEXT NOT NULL DEFAULT "private" CHECK(projects_visibility IN ("private","shared")), updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)',
        'CREATE TABLE IF NOT EXISTS crm_settings (key TEXT PRIMARY KEY, value TEXT NOT NULL, updated_by INTEGER, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(updated_by) REFERENCES users(id) ON DELETE SET NULL)',
        'CREATE TABLE IF NOT EXISTS crm_leads (id INTEGER PRIMARY KEY AUTOINCREMENT, company TEXT NOT NULL, contact_name TEXT, email TEXT, phone TEXT, mobile TEXT, website TEXT, tax_number TEXT, country TEXT, district TEXT, locality TEXT, source TEXT, status TEXT NOT NULL DEFAULT "NEW", priority TEXT NOT NULL DEFAULT "MEDIUM", potential TEXT, estimated_value REAL NOT NULL DEFAULT 0, probability INTEGER NOT NULL DEFAULT 10 CHECK(probability BETWEEN 0 AND 100), segment TEXT, notes TEXT, expected_decision_date TEXT, created_by INTEGER NOT NULL, assigned_to INTEGER NOT NULL, visibility TEXT NOT NULL DEFAULT "private" CHECK(visibility IN ("private","shared")), converted_customer_id INTEGER, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, last_activity_at DATETIME, FOREIGN KEY(created_by) REFERENCES users(id), FOREIGN KEY(assigned_to) REFERENCES users(id), FOREIGN KEY(converted_customer_id) REFERENCES erp_customers(id) ON DELETE SET NULL)',
        'CREATE TABLE IF NOT EXISTS crm_contacts (id INTEGER PRIMARY KEY AUTOINCREMENT, customer_id INTEGER, lead_id INTEGER, name TEXT NOT NULL, job_title TEXT, department TEXT, email TEXT, phone TEXT, mobile TEXT, is_primary INTEGER NOT NULL DEFAULT 0, notes TEXT, created_by INTEGER, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(customer_id) REFERENCES erp_customers(id) ON DELETE CASCADE, FOREIGN KEY(lead_id) REFERENCES crm_leads(id) ON DELETE CASCADE, FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL)',
        'CREATE TABLE IF NOT EXISTS crm_opportunities (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, company TEXT, customer_id INTEGER, lead_id INTEGER, contact_id INTEGER, description TEXT, stage TEXT NOT NULL DEFAULT "NEW" CHECK(stage IN ("NEW","CONTACTED","QUALIFIED","PROPOSAL","NEGOTIATION","WON","LOST")), estimated_value REAL NOT NULL DEFAULT 0, probability INTEGER NOT NULL DEFAULT 10 CHECK(probability BETWEEN 0 AND 100), expected_close_date TEXT, assigned_to INTEGER NOT NULL, created_by INTEGER NOT NULL, probability_manual INTEGER NOT NULL DEFAULT 0, last_activity_at DATETIME, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(customer_id) REFERENCES erp_customers(id) ON DELETE SET NULL, FOREIGN KEY(lead_id) REFERENCES crm_leads(id) ON DELETE SET NULL, FOREIGN KEY(contact_id) REFERENCES crm_contacts(id) ON DELETE SET NULL, FOREIGN KEY(assigned_to) REFERENCES users(id), FOREIGN KEY(created_by) REFERENCES users(id))',
        'CREATE TABLE IF NOT EXISTS crm_stage_history (id INTEGER PRIMARY KEY AUTOINCREMENT, opportunity_id INTEGER NOT NULL, user_id INTEGER NOT NULL, previous_stage TEXT NOT NULL, new_stage TEXT NOT NULL, changed_at DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(opportunity_id) REFERENCES crm_opportunities(id) ON DELETE CASCADE, FOREIGN KEY(user_id) REFERENCES users(id))',
        'CREATE TABLE IF NOT EXISTS crm_projects (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT NOT NULL UNIQUE, name TEXT NOT NULL, customer_id INTEGER, contact_id INTEGER, description TEXT, assigned_to INTEGER NOT NULL, team TEXT, priority TEXT NOT NULL DEFAULT "MEDIUM", status TEXT NOT NULL DEFAULT "PLANNED", progress INTEGER NOT NULL DEFAULT 0 CHECK(progress BETWEEN 0 AND 100), start_date TEXT, completion_date TEXT, value REAL NOT NULL DEFAULT 0, visibility TEXT NOT NULL DEFAULT "private" CHECK(visibility IN ("private","shared")), notes TEXT, created_by INTEGER NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(customer_id) REFERENCES erp_customers(id) ON DELETE SET NULL, FOREIGN KEY(contact_id) REFERENCES crm_contacts(id) ON DELETE SET NULL, FOREIGN KEY(assigned_to) REFERENCES users(id), FOREIGN KEY(created_by) REFERENCES users(id))',
        'CREATE TABLE IF NOT EXISTS crm_activities (id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT NOT NULL CHECK(type IN ("CALL","EMAIL","MEETING","TASK","FOLLOW_UP","QUOTE","NOTE","OTHER")), title TEXT NOT NULL, description TEXT, assigned_to INTEGER NOT NULL, created_by INTEGER NOT NULL, related_type TEXT, related_id INTEGER, start_datetime TEXT, due_datetime TEXT, completed_at DATETIME, status TEXT NOT NULL DEFAULT "PENDING", priority TEXT NOT NULL DEFAULT "MEDIUM", reminder_datetime TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(assigned_to) REFERENCES users(id), FOREIGN KEY(created_by) REFERENCES users(id))',
        'CREATE TABLE IF NOT EXISTS crm_timeline (id INTEGER PRIMARY KEY AUTOINCREMENT, related_type TEXT NOT NULL, related_id INTEGER NOT NULL, event_type TEXT NOT NULL, title TEXT NOT NULL, description TEXT, actor_id INTEGER, occurred_at DATETIME DEFAULT CURRENT_TIMESTAMP, metadata_json TEXT, FOREIGN KEY(actor_id) REFERENCES users(id) ON DELETE SET NULL)'
    ];
    $pdo->beginTransaction();
    try {
        foreach ($sql as $statement) $pdo->exec($statement);
        foreach (['crm_stale_lead_days'=>'15','crm_risk_attention_ratio'=>'1.5','crm_risk_high_ratio'=>'2.2'] as $key=>$value) {
            $stmt=$pdo->prepare('INSERT OR IGNORE INTO crm_settings(key,value) VALUES (?,?)'); $stmt->execute([$key,$value]);
        }
        foreach (['crm.view'=>'Consultar CRM','crm.manage'=>'Gerir CRM','crm.admin'=>'Administrar CRM'] as $code=>$label) {
            $stmt=$pdo->prepare('INSERT OR IGNORE INTO erp_permissions(code,label,description) VALUES (?,?,?)'); $stmt->execute([$code,$label,'Módulo comercial CRM']);
        }
        foreach (['crm.view','crm.manage','crm.admin'] as $code) $pdo->exec("CREATE INDEX IF NOT EXISTS idx_".str_replace('.','_',$code)." ON erp_permissions(code)");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_crm_leads_owner ON crm_leads(assigned_to,created_by,visibility)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_crm_activities_day ON crm_activities(assigned_to,status,due_datetime)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_crm_opportunities_stage ON crm_opportunities(stage,assigned_to)');
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
}
