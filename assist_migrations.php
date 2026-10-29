<?php
declare(strict_types=1);

/** Additive, transactional migration. A current schema performs no writes. */
function assist_run_migrations(PDO $pdo)
{
    $exists = $pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='assist_settings'")->fetchColumn();
    if ($exists && $pdo->query("SELECT value FROM assist_settings WHERE key='schema_version'")->fetchColumn() === '1') return;
    $pdo->beginTransaction();
    try {
        $sql = [
            'CREATE TABLE IF NOT EXISTS assist_settings (key TEXT PRIMARY KEY, value TEXT NOT NULL)',
            'CREATE TABLE IF NOT EXISTS assist_categories (id INTEGER PRIMARY KEY, name TEXT NOT NULL UNIQUE, parent_id INTEGER REFERENCES assist_categories(id), required_permission TEXT NOT NULL DEFAULT "", created_by INTEGER REFERENCES users(id))',
            'CREATE TABLE IF NOT EXISTS assist_articles (id INTEGER PRIMARY KEY, category_id INTEGER NOT NULL REFERENCES assist_categories(id), version INTEGER NOT NULL DEFAULT 1, status TEXT NOT NULL DEFAULT "draft" CHECK(status IN ("draft","review","approved","published","archived")), title TEXT NOT NULL, content_json TEXT NOT NULL, search_text TEXT NOT NULL, language TEXT NOT NULL DEFAULT "pt" CHECK(language IN ("pt","en","hi")), author_id INTEGER NOT NULL REFERENCES users(id), validator_id INTEGER REFERENCES users(id), approved_by INTEGER REFERENCES users(id), approved_at TEXT, reviewed_at TEXT, expires_on TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)',
            'CREATE TABLE IF NOT EXISTS assist_versions (id INTEGER PRIMARY KEY, article_id INTEGER NOT NULL REFERENCES assist_articles(id), version INTEGER NOT NULL, snapshot_json TEXT NOT NULL, author_id INTEGER NOT NULL REFERENCES users(id), created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE(article_id,version))',
            'CREATE TABLE IF NOT EXISTS assist_approvals (id INTEGER PRIMARY KEY, article_id INTEGER NOT NULL, version INTEGER NOT NULL, decision TEXT NOT NULL CHECK(decision IN ("approved","rejected")), actor_id INTEGER NOT NULL REFERENCES users(id), reason TEXT NOT NULL DEFAULT "", created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(article_id,version) REFERENCES assist_versions(article_id,version))',
            'CREATE TABLE IF NOT EXISTS assist_conversations (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL REFERENCES users(id), created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)',
            'CREATE TABLE IF NOT EXISTS assist_messages (id INTEGER PRIMARY KEY, conversation_id INTEGER NOT NULL REFERENCES assist_conversations(id) ON DELETE CASCADE, user_id INTEGER NOT NULL REFERENCES users(id), question TEXT NOT NULL, answer_json TEXT NOT NULL, context_json TEXT NOT NULL DEFAULT "{}", outcome TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)',
            'CREATE TABLE IF NOT EXISTS assist_sources (message_id INTEGER NOT NULL REFERENCES assist_messages(id) ON DELETE CASCADE, article_id INTEGER NOT NULL, version INTEGER NOT NULL, PRIMARY KEY(message_id,article_id), FOREIGN KEY(article_id,version) REFERENCES assist_versions(article_id,version))',
            'CREATE TABLE IF NOT EXISTS assist_feedback (message_id INTEGER NOT NULL REFERENCES assist_messages(id) ON DELETE CASCADE, user_id INTEGER NOT NULL REFERENCES users(id), useful INTEGER NOT NULL CHECK(useful IN (0,1)), created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(message_id,user_id))',
            'CREATE TABLE IF NOT EXISTS assist_problems (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL REFERENCES users(id), message_id INTEGER REFERENCES assist_messages(id) ON DELETE SET NULL, description TEXT NOT NULL, equipment TEXT NOT NULL DEFAULT "", operation_ref TEXT NOT NULL DEFAULT "", priority TEXT NOT NULL CHECK(priority IN ("low","normal","high")), solution TEXT NOT NULL DEFAULT "", effective INTEGER NOT NULL DEFAULT 0 CHECK(effective IN (0,1)), article_id INTEGER REFERENCES assist_articles(id), created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)',
            'CREATE INDEX IF NOT EXISTS idx_assist_articles_state ON assist_articles(status,category_id,language,expires_on)',
            'CREATE INDEX IF NOT EXISTS idx_assist_messages_owner ON assist_messages(user_id,created_at)',
            'CREATE INDEX IF NOT EXISTS idx_assist_conversations_owner ON assist_conversations(user_id)',
            'CREATE INDEX IF NOT EXISTS idx_assist_sources_article ON assist_sources(article_id,version)',
            'CREATE INDEX IF NOT EXISTS idx_assist_problems_date ON assist_problems(created_at)'
        ];
        foreach ($sql as $statement) $pdo->exec($statement);
        $settings = ['enabled'=>'0','pilot_users'=>'','retention_days'=>'180','schema_version'=>'1'];
        $s = $pdo->prepare('INSERT OR IGNORE INTO assist_settings(key,value) VALUES (?,?)');
        foreach ($settings as $key=>$value) $s->execute([$key,$value]);
        $s = $pdo->prepare('INSERT OR IGNORE INTO assist_categories(name) VALUES (?)');
        foreach (['Tintas','Impressão Flexográfica','Anilox e Rolos','Laminação','Corte e Cose','Manutenção','Qualidade','Segurança','Equipamentos','Procedimentos Gerais','GesTISSER — Ajuda ao Utilizador'] as $name) $s->execute([$name]);
        $s = $pdo->prepare('INSERT OR IGNORE INTO erp_permissions(code,label,description) VALUES (?,?,?)');
        foreach (['use'=>'Consultar Assist','edit'=>'Editar conhecimento','validate'=>'Validar conhecimento','publish'=>'Publicar conhecimento','admin'=>'Administrar Assist','restricted'=>'Consultar conhecimento restrito'] as $code=>$label) $s->execute(['assist.'.$code,$label,'GesTISSER Assist']);
        foreach ($pdo->query('SELECT id,name FROM assist_categories')->fetchAll(PDO::FETCH_ASSOC) as $c) $s->execute(['assist.validate.category.'.$c['id'],'Validar categoria '.$c['name'],'GesTISSER Assist']);
        try {
            $pdo->exec('CREATE VIRTUAL TABLE IF NOT EXISTS assist_search USING fts5(title, search_text)');
        } catch (Throwable $e) { /* LIKE fallback has no optional dependencies. */ }
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
}
