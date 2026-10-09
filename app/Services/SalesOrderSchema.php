<?php
/** Incremental migration, automatically ensured on authorized development access. */
final class SalesOrderSchema
{
    public static function developmentAllowed($environment,$root,$databasePath) {
        if(in_array($environment,['development','gestisser-dev','test'],true))return true;
        // A deployed dev copy may inherit the default "production" setting.
        // Trust its filesystem location, never the request URL, and require its own DB.
        $root=realpath($root);$databasePath=realpath($databasePath);
        return $root!==false&&$databasePath!==false&&basename($root)==='gestisser-dev'
            &&strpos($databasePath,$root.DIRECTORY_SEPARATOR)===0;
    }
    public static function ready(PDO $pdo) {
        $objects=['erp_sales_orders','erp_sales_order_lines','erp_sales_order_work_orders','erp_sales_order_deliveries',
            'idx_sales_orders_customer_date','idx_sales_orders_date','idx_sales_orders_expected','idx_sales_lines_article','idx_sales_of_line','idx_sales_delivery_line'];
        $s=$pdo->prepare('SELECT COUNT(*) FROM sqlite_master WHERE name IN ('.implode(',',array_fill(0,count($objects),'?')).') AND type IN ("table","index")');
        $s->execute($objects);if((int)$s->fetchColumn()!==count($objects))return false;
        $s=$pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name='erp_number_sequences'");$s->execute();if(!$s->fetchColumn())return false;
        $s=$pdo->prepare('SELECT 1 FROM erp_number_sequences WHERE code=?');$s->execute(['sales_order']);return (bool)$s->fetchColumn();
    }
    public static function ensure(PDO $pdo,$environment,$root,$databasePath) {
        if(self::ready($pdo))return true;
        if(!self::developmentAllowed($environment,$root,$databasePath))return false;
        $queryOnly=(int)$pdo->query('PRAGMA query_only')->fetchColumn();
        try {
            $pdo->exec('PRAGMA query_only=OFF');
            self::migrate($pdo);
        } finally {$pdo->exec('PRAGMA query_only='.($queryOnly?'ON':'OFF'));}
        return self::ready($pdo);
    }
    public static function migrate(PDO $pdo) {
        $pdo->beginTransaction();
        try {
            foreach([
                'CREATE TABLE IF NOT EXISTS erp_sales_orders (id INTEGER PRIMARY KEY AUTOINCREMENT, order_number TEXT NOT NULL UNIQUE, customer_id INTEGER NOT NULL REFERENCES erp_customers(id) ON DELETE RESTRICT, order_date TEXT NOT NULL, expected_date TEXT, customer_reference TEXT, status TEXT NOT NULL DEFAULT "Rascunho", source_system TEXT NOT NULL DEFAULT "GesTISSER", original_id TEXT, original_number TEXT, original_data_json TEXT, notes TEXT, revision INTEGER NOT NULL DEFAULT 1, created_by INTEGER REFERENCES users(id), updated_by INTEGER REFERENCES users(id), created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE(source_system,original_id))',
                'CREATE TABLE IF NOT EXISTS erp_sales_order_lines (id INTEGER PRIMARY KEY AUTOINCREMENT, sales_order_id INTEGER NOT NULL REFERENCES erp_sales_orders(id) ON DELETE RESTRICT, line_number INTEGER NOT NULL, finished_product_id INTEGER NOT NULL REFERENCES erp_finished_products(id) ON DELETE RESTRICT, article_code TEXT NOT NULL, description TEXT NOT NULL, quantity REAL NOT NULL CHECK(quantity>0), unit_id INTEGER REFERENCES erp_units(id) ON DELETE RESTRICT, unit_code TEXT, unit_price REAL CHECK(unit_price>=0), discount_percent REAL CHECK(discount_percent>=0 AND discount_percent<=100), line_total REAL, expected_date TEXT, original_data_json TEXT, UNIQUE(sales_order_id,line_number))',
                'CREATE INDEX IF NOT EXISTS idx_sales_orders_customer_date ON erp_sales_orders(customer_id,order_date,id)',
                'CREATE INDEX IF NOT EXISTS idx_sales_orders_date ON erp_sales_orders(order_date,id)',
                'CREATE INDEX IF NOT EXISTS idx_sales_orders_expected ON erp_sales_orders(expected_date,status)',
                'CREATE INDEX IF NOT EXISTS idx_sales_lines_article ON erp_sales_order_lines(finished_product_id,sales_order_id)',
                'CREATE TABLE IF NOT EXISTS erp_sales_order_work_orders (id INTEGER PRIMARY KEY AUTOINCREMENT, sales_order_line_id INTEGER NOT NULL REFERENCES erp_sales_order_lines(id) ON DELETE RESTRICT, production_order_id INTEGER NOT NULL UNIQUE REFERENCES erp_production_orders(id) ON DELETE RESTRICT, linked_by INTEGER REFERENCES users(id), created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)',
                'CREATE INDEX IF NOT EXISTS idx_sales_of_line ON erp_sales_order_work_orders(sales_order_line_id)',
                'CREATE TABLE IF NOT EXISTS erp_sales_order_deliveries (id INTEGER PRIMARY KEY AUTOINCREMENT, sales_order_line_id INTEGER NOT NULL REFERENCES erp_sales_order_lines(id) ON DELETE RESTRICT, stock_movement_id INTEGER NOT NULL UNIQUE REFERENCES erp_stock_movements(id) ON DELETE RESTRICT, delivery_reference TEXT NOT NULL, confirmed_by INTEGER NOT NULL REFERENCES users(id), created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)',
                'CREATE INDEX IF NOT EXISTS idx_sales_delivery_line ON erp_sales_order_deliveries(sales_order_line_id)'
            ] as $sql)$pdo->exec($sql);
            $pdo->prepare('INSERT OR IGNORE INTO erp_number_sequences(code,prefix,next_number,padding) VALUES (?,?,1,5)')->execute(['sales_order','EC-']);
            $pdo->commit();
        } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
}
