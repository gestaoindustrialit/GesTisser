<?php

final class HrEpiInputException extends RuntimeException {}

final class HrEpi
{
    const DECLARATION_VERSION = '1';
    const DECLARATION = 'Declaro que recebi da TISSER, LDA. os equipamentos de proteção individual acima identificados, tendo sido informado(a) sobre a sua correta utilização, conservação, limitações e necessidade de comunicar qualquer dano ou anomalia.';
    const CATEGORIES = ['Mãos', 'Olhos', 'Ouvidos', 'Pés', 'Vias respiratórias', 'Corpo', 'Outros'];
    const REASONS = ['Primeira entrega', 'Substituição', 'Reposição'];

    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function migrate()
    {
        if ($this->pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='hr_epi_outbox'")->fetchColumn()) {
            return;
        }
        $this->write(function () {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS hr_epi_catalog (
                    id TEXT PRIMARY KEY, name TEXT NOT NULL, category TEXT NOT NULL,
                    reference TEXT NOT NULL, model TEXT NOT NULL, size TEXT NOT NULL, standard TEXT NOT NULL,
                    replacement_days INTEGER, notes TEXT NOT NULL, active INTEGER NOT NULL
                );
                CREATE TABLE IF NOT EXISTS hr_epi_operations (
                    id TEXT PRIMARY KEY, employee_id INTEGER NOT NULL, employee_name TEXT NOT NULL,
                    employee_number TEXT NOT NULL, delivered_at TEXT NOT NULL, actor_id INTEGER NOT NULL,
                    actor_name TEXT NOT NULL, confirmed_at TEXT, confirmed_by INTEGER, proof_id TEXT UNIQUE,
                    confirmation_method TEXT, declaration_version TEXT, declaration TEXT
                );
                CREATE TABLE IF NOT EXISTS hr_epi_items (
                    id TEXT PRIMARY KEY, operation_id TEXT NOT NULL REFERENCES hr_epi_operations(id),
                    epi_id TEXT NOT NULL REFERENCES hr_epi_catalog(id), name TEXT NOT NULL,
                    category TEXT NOT NULL, reference TEXT NOT NULL, size TEXT NOT NULL,
                    quantity INTEGER NOT NULL CHECK(quantity > 0), reason TEXT NOT NULL, notes TEXT NOT NULL,
                    replacement_date TEXT
                );
                CREATE INDEX IF NOT EXISTS hr_epi_items_operation ON hr_epi_items(operation_id);
                CREATE INDEX IF NOT EXISTS hr_epi_operations_employee ON hr_epi_operations(employee_id, delivered_at);
                CREATE TABLE IF NOT EXISTS hr_epi_events (
                    id INTEGER PRIMARY KEY, item_id TEXT NOT NULL REFERENCES hr_epi_items(id),
                    kind TEXT NOT NULL, notes TEXT NOT NULL, quantity INTEGER, actor_id INTEGER NOT NULL,
                    actor_name TEXT NOT NULL, created_at TEXT NOT NULL
                );
                CREATE INDEX IF NOT EXISTS hr_epi_events_item ON hr_epi_events(item_id);
                CREATE TABLE IF NOT EXISTS hr_epi_pin_attempts (
                    employee_id INTEGER PRIMARY KEY, attempts INTEGER NOT NULL, expires_at INTEGER NOT NULL
                );
                CREATE TABLE IF NOT EXISTS hr_epi_outbox (
                    kind TEXT NOT NULL, entity_id TEXT NOT NULL, revision INTEGER NOT NULL DEFAULT 1,
                    synced_revision INTEGER NOT NULL DEFAULT 0, attempts INTEGER NOT NULL DEFAULT 0,
                    retry_at INTEGER NOT NULL DEFAULT 0, error TEXT, PRIMARY KEY(kind, entity_id)
                );
            ");
        });
    }

    private function write(callable $operation)
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $result = $operation();
            $this->pdo->exec('COMMIT');
            return $result;
        } catch (Throwable $e) {
            $this->pdo->exec('ROLLBACK');
            throw $e;
        }
    }

    public static function now()
    {
        return new DateTimeImmutable('now', new DateTimeZone('Europe/Lisbon'));
    }

    private static function timestamp()
    {
        return self::now()->format(DateTime::ATOM);
    }

    public static function field(array $data, $key, $limit = 255)
    {
        $value = $data[$key] ?? '';
        if (!is_string($value) || strlen($value) > $limit || !preg_match('//u', $value)) {
            throw new HrEpiInputException('Valor inválido no formulário.');
        }
        return trim($value);
    }

    public static function number($value, $max)
    {
        if (!is_scalar($value) || !preg_match('/^[0-9]{1,10}$/D', (string) $value)
            || (int) $value < 1 || (int) $value > $max) {
            throw new HrEpiInputException('Número inválido no formulário.');
        }
        return (int) $value;
    }

    public function query($sql, array $params = [])
    {
        $query = $this->pdo->prepare($sql);
        $query->execute($params);
        return $query;
    }

    private function queue($kind, $id)
    {
        $this->query('INSERT OR IGNORE INTO hr_epi_outbox(kind,entity_id,revision) VALUES (?,?,0)', [$kind, $id]);
        $this->query('UPDATE hr_epi_outbox SET revision=revision+1, attempts=0, retry_at=0, error=NULL WHERE kind=? AND entity_id=?', [$kind, $id]);
    }

    public function saveCatalog(array $data, array $actor)
    {
        $id = self::field($data, 'id');
        $name = self::field($data, 'name');
        $category = self::field($data, 'category');
        $days = self::field($data, 'replacement_days');
        if ($name === '' || !in_array($category, self::CATEGORIES, true)) {
            throw new HrEpiInputException('Indique a designação e a categoria.');
        }
        $fields = [$name, $category, self::field($data, 'reference'), self::field($data, 'model'),
            self::field($data, 'size'), self::field($data, 'standard'),
            $days === '' ? null : self::number($days, 36500), self::field($data, 'notes', 2000),
            (int) (($data['active'] ?? '') === '1')];
        return $this->write(function () use ($id, $fields, $actor) {
            if ($id === '') {
                $id = bin2hex(random_bytes(16));
                $this->query('INSERT INTO hr_epi_catalog(name,category,reference,model,size,standard,replacement_days,notes,active,id)
                    VALUES (?,?,?,?,?,?,?,?,?,?)', array_merge($fields, [$id]));
            } elseif (!$this->query('UPDATE hr_epi_catalog SET name=?,category=?,reference=?,model=?,size=?,standard=?,
                replacement_days=?,notes=?,active=? WHERE id=?', array_merge($fields, [$id]))->rowCount()) {
                throw new HrEpiInputException('Equipamento indisponível.');
            }
            $this->queue('catalog', $id);
            AuditLog::write($this->pdo, $actor['id'], 'hr.epi.catalog', ['id' => $id]);
            return $id;
        });
    }

    public function deliver($employeeId, array $items, array $actor)
    {
        if (!$items || count($items) > 50) {
            throw new HrEpiInputException('Registe entre 1 e 50 equipamentos por entrega.');
        }
        return $this->write(function () use ($employeeId, $items, $actor) {
            $employee = $this->query('SELECT id,name,user_number FROM users WHERE id=? AND is_active=1', [$employeeId])->fetch(PDO::FETCH_ASSOC);
            if (!$employee) {
                throw new HrEpiInputException('Colaborador indisponível.');
            }
            $operation = bin2hex(random_bytes(16));
            $now = self::now();
            $this->query('INSERT INTO hr_epi_operations(id,employee_id,employee_name,employee_number,delivered_at,actor_id,actor_name)
                VALUES (?,?,?,?,?,?,?)', [$operation, $employee['id'], $employee['name'], (string) $employee['user_number'],
                $now->format(DateTime::ATOM), $actor['id'], $actor['name']]);
            foreach ($items as $item) {
                if (!is_array($item)) {
                    throw new HrEpiInputException('Linha de entrega inválida.');
                }
                $epi = $this->query('SELECT * FROM hr_epi_catalog WHERE id=? AND active=1', [self::field($item, 'epi_id')])->fetch(PDO::FETCH_ASSOC);
                $reason = self::field($item, 'reason');
                if (!$epi || !in_array($reason, self::REASONS, true)) {
                    throw new HrEpiInputException('Selecione um equipamento ativo e um motivo em cada linha.');
                }
                $id = bin2hex(random_bytes(16));
                $size = self::field($item, 'size');
                $date = $epi['replacement_days'] === null ? null : $now->modify('+' . (int) $epi['replacement_days'] . ' days')->format('Y-m-d');
                $this->query('INSERT INTO hr_epi_items(id,operation_id,epi_id,name,category,reference,size,quantity,reason,notes,replacement_date)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?)', [$id, $operation, $epi['id'], $epi['name'], $epi['category'], $epi['reference'],
                    $size === '' ? $epi['size'] : $size, self::number($item['quantity'] ?? '', 9999), $reason,
                    self::field($item, 'notes', 2000), $date]);
                $this->queue('delivery', $id);
            }
            AuditLog::write($this->pdo, $actor['id'], 'hr.epi.deliver', ['operation' => $operation, 'items' => count($items)]);
            return $operation;
        });
    }

    public function operation($id)
    {
        return $this->query('SELECT * FROM hr_epi_operations WHERE id=?', [$id])->fetch(PDO::FETCH_ASSOC);
    }

    public function canConfirm(array $operation, array $actor, $canDeliver)
    {
        return (int) $operation['employee_id'] === (int) $actor['id']
            || ($canDeliver && (int) $operation['actor_id'] === (int) $actor['id']);
    }

    public function confirm($id, array $actor, $canDeliver, $accepted, $pin)
    {
        if (!$accepted) {
            throw new HrEpiInputException('Aceite a declaração de receção.');
        }
        $error = $this->write(function () use ($id, $actor, $canDeliver, $pin) {
            $operation = $this->operation($id);
            if (!$operation || !$this->canConfirm($operation, $actor, $canDeliver)) {
                return 'Entrega indisponível.';
            }
            if ($operation['confirmed_at'] !== null) {
                return '';
            }
            $employeeId = $operation['employee_id'];
            $attempt = $this->query('SELECT * FROM hr_epi_pin_attempts WHERE employee_id=?', [$employeeId])->fetch(PDO::FETCH_ASSOC);
            $now = time();
            $inWindow = $attempt && (int) $attempt['expires_at'] > $now;
            $count = $inWindow ? (int) $attempt['attempts'] : 0;
            if ($count >= 5) {
                return 'Limite de tentativas atingido. Volte a tentar mais tarde.';
            }
            $employee = $this->query('SELECT pin_code_hash,pin_code FROM users WHERE id=? AND is_active=1', [$employeeId])->fetch(PDO::FETCH_ASSOC);
            if (!$employee || !verify_pin_code($employee, $pin)) {
                $this->query('INSERT OR REPLACE INTO hr_epi_pin_attempts(employee_id,attempts,expires_at) VALUES (?,?,?)',
                    [$employeeId, $count + 1, $inWindow ? $attempt['expires_at'] : $now + 900]);
                AuditLog::write($this->pdo, $actor['id'], 'hr.epi.pin_rejected', ['operation' => $id]);
                return 'PIN inválido.';
            }
            $hash = trim((string) $employee['pin_code_hash']);
            if ($hash === '' || preg_match('/^\d{6}$/D', $hash) || preg_match('/^\d{6}$/D', trim((string) $employee['pin_code']))) {
                $this->query("UPDATE users SET pin_code_hash=?, pin_code='' WHERE id=?", [password_hash($pin, PASSWORD_DEFAULT), $employeeId]);
            }
            $this->query('DELETE FROM hr_epi_pin_attempts WHERE employee_id=?', [$employeeId]);
            $this->query('UPDATE hr_epi_operations SET confirmed_at=?,confirmed_by=?,proof_id=?,confirmation_method=?,
                declaration_version=?,declaration=? WHERE id=?', [self::timestamp(), $employeeId, bin2hex(random_bytes(16)),
                'PIN individual', self::DECLARATION_VERSION, self::DECLARATION, $id]);
            foreach ($this->query('SELECT id FROM hr_epi_items WHERE operation_id=?', [$id])->fetchAll(PDO::FETCH_COLUMN) as $itemId) {
                $this->queue('delivery', $itemId);
            }
            AuditLog::write($this->pdo, $actor['id'], 'hr.epi.confirm', ['operation' => $id, 'employee' => (int) $employeeId]);
            return '';
        });
        if ($error !== '') {
            throw new HrEpiInputException($error);
        }
    }

    public function recordEvent($id, $kind, $notes, $quantity, array $actor)
    {
        if (!in_array($kind, ['return', 'correction'], true) || $notes === '') {
            throw new HrEpiInputException('Indique o motivo da devolução ou os dados da retificação.');
        }
        $this->write(function () use ($id, $kind, $notes, $quantity, $actor) {
            $item = $this->query('SELECT quantity FROM hr_epi_items WHERE id=?', [$id])->fetch(PDO::FETCH_ASSOC);
            if (!$item) {
                throw new HrEpiInputException('Equipamento entregue indisponível.');
            }
            if ($kind === 'return') {
                $returned = $this->query("SELECT COALESCE(SUM(quantity),0) FROM hr_epi_events WHERE item_id=? AND kind='return'", [$id])->fetchColumn();
                if ($quantity > (int) $item['quantity'] - (int) $returned) {
                    throw new HrEpiInputException('A quantidade excede o total por devolver.');
                }
            }
            $this->query('INSERT INTO hr_epi_events(item_id,kind,notes,quantity,actor_id,actor_name,created_at)
                VALUES (?,?,?,?,?,?,?)', [$id, $kind, $notes, $quantity, $actor['id'], $actor['name'], self::timestamp()]);
            AuditLog::write($this->pdo, $actor['id'], 'hr.epi.' . $kind, ['item' => $id, 'event' => (int) $this->pdo->lastInsertId()]);
        });
    }

    public function history(array $filters)
    {
        $where = [];
        $params = [];
        foreach (['employee' => 'o.employee_id', 'operation' => 'o.id', 'equipment' => 'i.epi_id', 'category' => 'i.category'] as $key => $column) {
            if (!empty($filters[$key])) {
                $where[] = $column . '=?';
                $params[] = $filters[$key];
            }
        }
        foreach (['from' => '>=', 'to' => '<='] as $key => $operator) {
            if (!empty($filters[$key])) {
                $where[] = 'substr(o.delivered_at,1,10)' . $operator . '?';
                $params[] = $filters[$key];
            }
        }
        return $this->query("SELECT i.*, o.employee_id,o.employee_name,o.employee_number,o.delivered_at,
                o.confirmed_at,o.proof_id,o.actor_name,q.error,q.revision,q.synced_revision,
                COALESCE((SELECT SUM(e.quantity) FROM hr_epi_events e WHERE e.item_id=i.id AND e.kind='return'),0) AS returned,
                (NOT EXISTS (SELECT 1 FROM hr_epi_items newer JOIN hr_epi_operations n ON n.id=newer.operation_id
                    WHERE newer.epi_id=i.epi_id AND n.employee_id=o.employee_id AND n.rowid>o.rowid AND newer.reason='Substituição')) AS current_cycle
            FROM hr_epi_items i JOIN hr_epi_operations o ON o.id=i.operation_id
            JOIN hr_epi_outbox q ON q.kind='delivery' AND q.entity_id=i.id"
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY o.delivered_at DESC,o.rowid DESC,i.rowid', $params)->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function syncStatus(array $row)
    {
        if ($row['error'] !== null) {
            return 'Erro';
        }
        return (int) $row['revision'] === (int) $row['synced_revision'] ? 'Sincronizado' : 'Pendente';
    }
}
