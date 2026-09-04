<?php
// Load the complete application helper layer. Loading config.php directly leaves
// shared view helpers (for example, has_shopfloor_only_navigation()) undefined
// when the organogram renders the global header.
require_once __DIR__ . '/helpers.php';

function gt_column_exists(PDO $pdo, string $table, string $column): bool { foreach ($pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC) as $r) { if (($r['name'] ?? '') === $column) return true; } return false; }
function gt_table_exists(PDO $pdo, string $table): bool { $s=$pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=? LIMIT 1"); $s->execute([$table]); return (bool)$s->fetchColumn(); }
function gt_add_column(PDO $pdo, string $table, string $column, string $def) { if (!gt_column_exists($pdo,$table,$column)) $pdo->exec('ALTER TABLE '.$table.' ADD COLUMN '.$column.' '.$def); }
function gt_org_audit(PDO $pdo, int $userId, string $action, string $entity, int $entityId, array $old=[], array $new=[]) { $stmt=$pdo->prepare('INSERT INTO audit_logs(user_id, action, details_json) VALUES (?,?,?)'); $stmt->execute([$userId, $action, json_encode(['entity'=>$entity,'id'=>$entityId,'old'=>$old,'new'=>$new], JSON_UNESCAPED_UNICODE)]); }

function gt_run_org_migrations(PDO $pdo) {
    $pdo->exec('PRAGMA foreign_keys = ON');
    gt_add_column($pdo,'users','manager_user_id','INTEGER NULL'); gt_add_column($pdo,'users','capacity_percent','INTEGER NOT NULL DEFAULT 100'); gt_add_column($pdo,'users','employee_number','TEXT NULL'); gt_add_column($pdo,'users','job_title','TEXT NULL'); gt_add_column($pdo,'users','professional_phone','TEXT NULL'); gt_add_column($pdo,'users','professional_email','TEXT NULL'); gt_add_column($pdo,'users','hr_notes','TEXT NULL'); gt_add_column($pdo,'users','org_sort_order','INTEGER NOT NULL DEFAULT 0');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_users_manager_user_id ON users(manager_user_id)'); $pdo->exec('CREATE INDEX IF NOT EXISTS idx_users_department_schedule ON users(department, schedule_id)');
    $pdo->exec("CREATE TABLE IF NOT EXISTS erp_machines (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT NOT NULL UNIQUE, name TEXT NOT NULL, brand TEXT, model TEXT, serial_number TEXT, manufacturing_year INTEGER, department_id INTEGER, location TEXT, owner_user_id INTEGER, status TEXT NOT NULL DEFAULT 'operational' CHECK(status IN ('operational','maintenance','broken','stopped','inactive','limited')), criticality TEXT NOT NULL DEFAULT 'medium' CHECK(criticality IN ('low','medium','high','critical')), nominal_capacity TEXT, capacity_unit TEXT, cycle_time TEXT, cycle_time_unit TEXT, operators_required INTEGER NOT NULL DEFAULT 0 CHECK(operators_required >= 0), supplier TEXT, service_provider TEXT, purchase_date TEXT, next_maintenance_date TEXT, manual_url TEXT, characteristics TEXT, risks TEXT, limitations TEXT, notes TEXT, is_active INTEGER NOT NULL DEFAULT 1, created_by INTEGER, updated_by INTEGER, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, deleted_at DATETIME, FOREIGN KEY(department_id) REFERENCES hr_departments(id) ON DELETE SET NULL, FOREIGN KEY(owner_user_id) REFERENCES users(id) ON DELETE SET NULL)");
    $machineColumns = array('capacity_unit TEXT','cycle_time_unit TEXT','supplier TEXT','characteristics TEXT','risks TEXT','limitations TEXT','deleted_at DATETIME'); foreach($machineColumns as $d){$parts=explode(' ',$d); $c=$parts[0]; gt_add_column($pdo,'erp_machines',$c,substr($d,strlen($c)+1));}
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_erp_machines_code ON erp_machines(code)'); $pdo->exec('CREATE INDEX IF NOT EXISTS idx_erp_machines_filters ON erp_machines(status, criticality, department_id, is_active)');
    $pdo->exec("CREATE TABLE IF NOT EXISTS erp_machine_attachments (id INTEGER PRIMARY KEY AUTOINCREMENT, machine_id INTEGER NOT NULL, original_name TEXT NOT NULL, file_path TEXT NOT NULL, mime_type TEXT, file_size INTEGER NOT NULL DEFAULT 0, uploaded_by INTEGER, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, deleted_at DATETIME, FOREIGN KEY(machine_id) REFERENCES erp_machines(id) ON DELETE CASCADE, FOREIGN KEY(uploaded_by) REFERENCES users(id) ON DELETE SET NULL)");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_erp_machine_attachments_machine ON erp_machine_attachments(machine_id, deleted_at)');
    $pdo->exec("CREATE TABLE IF NOT EXISTS hr_machine_competencies (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, machine_id INTEGER NOT NULL, level INTEGER NOT NULL DEFAULT 0 CHECK(level BETWEEN 0 AND 4), assessed_at TEXT, expiry_date TEXT, training TEXT, limitations TEXT, evidence TEXT, notes TEXT, assessed_by_user_id INTEGER, created_by INTEGER, updated_by INTEGER, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, deleted_at DATETIME, UNIQUE(user_id, machine_id), FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE, FOREIGN KEY(machine_id) REFERENCES erp_machines(id) ON DELETE CASCADE)");
    $competencyColumns = array('training TEXT','limitations TEXT','evidence TEXT','deleted_at DATETIME'); foreach($competencyColumns as $d){$parts=explode(' ',$d); $c=$parts[0]; gt_add_column($pdo,'hr_machine_competencies',$c,substr($d,strlen($c)+1));}
    $pdo->exec("CREATE TABLE IF NOT EXISTS company_duty_sheets (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, job_role TEXT, department_id INTEGER, responsible_user_id INTEGER, backup_user_id INTEGER, secondary_backup_user_id INTEGER, status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','review','validated','archived','inactive')), review_date TEXT, purpose TEXT, responsibilities TEXT, daily_tasks TEXT, weekly_tasks TEXT, monthly_tasks TEXT, annual_tasks TEXT, authority_text TEXT, kpis TEXT, systems_text TEXT, risks TEXT, notes TEXT, created_by INTEGER, updated_by INTEGER, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, deleted_at DATETIME, FOREIGN KEY(department_id) REFERENCES hr_departments(id) ON DELETE SET NULL)");
    $dutyColumns = array('job_role TEXT','weekly_tasks TEXT','monthly_tasks TEXT','annual_tasks TEXT','risks TEXT','deleted_at DATETIME'); foreach($dutyColumns as $d){$parts=explode(' ',$d); $c=$parts[0]; gt_add_column($pdo,'company_duty_sheets',$c,substr($d,strlen($c)+1));}
    $pdo->exec('CREATE TABLE IF NOT EXISTS company_duty_sheet_machines (id INTEGER PRIMARY KEY AUTOINCREMENT, duty_sheet_id INTEGER NOT NULL, machine_id INTEGER NOT NULL, minimum_level INTEGER NOT NULL DEFAULT 3 CHECK(minimum_level BETWEEN 0 AND 4), notes TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE(duty_sheet_id,machine_id), FOREIGN KEY(duty_sheet_id) REFERENCES company_duty_sheets(id) ON DELETE CASCADE, FOREIGN KEY(machine_id) REFERENCES erp_machines(id) ON DELETE CASCADE)');
}
function gt_is_hr_allowed(PDO $pdo,int $uid): bool { $stmt=$pdo->prepare('SELECT is_admin, access_profile FROM users WHERE id = ?'); $stmt->execute([$uid]); $row=$stmt->fetch(PDO::FETCH_ASSOC); return $row && ((int)($row['is_admin']??0)===1 || (string)($row['access_profile']??'')==='RH'); }
function gt_is_erp_allowed(PDO $pdo,array $u): bool { return (int)($u['is_admin']??0)===1 || in_array((string)($u['access_profile']??''), ['ERP','Admin','Gestão'], true); }
function gt_prevents_cycle(PDO $pdo, int $userId, $managerId): bool { if (!$managerId) return true; if ($userId===$managerId) return false; $seen=[]; while($managerId){ if ($managerId===$userId || isset($seen[$managerId])) return false; $seen[$managerId]=1; $s=$pdo->prepare('SELECT manager_user_id FROM users WHERE id=?'); $s->execute([$managerId]); $managerId=(int)$s->fetchColumn(); } return true; }
function gt_duty_risks(array $d): array { $r=[]; if (empty($d['backup_user_id'])) $r[]='Sem substituto principal'; if (!empty($d['review_date']) && $d['review_date'] < date('Y-m-d')) $r[]='Revisão vencida'; return $r; }

/** The shopfloor account is a terminal/bot identity, not an employee. */
function gt_org_employee_sql(string $alias = 'u'): string
{
    return "LOWER(TRIM(COALESCE({$alias}.username, ''))) <> 'shopfloor'"
        . " AND LOWER(TRIM(COALESCE({$alias}.name, ''))) <> 'shopfloor'";
}

/**
 * Build the hierarchy rows used by both the screen and the PDF export.
 * Managers hidden by a filter become roots in the filtered result.
 */
function gt_org_levels(array $people): array
{
    $peopleById = [];
    foreach ($people as $person) {
        $peopleById[(int) $person['id']] = $person;
    }

    $byManager = [];
    foreach ($people as $person) {
        $managerId = (int) ($person['manager_user_id'] ?? 0);
        if ($managerId > 0 && !isset($peopleById[$managerId])) {
            $managerId = 0;
        }
        $byManager[$managerId][] = $person;
    }

    $levels = [];
    $visited = [];
    // Do not declare a void return type here: production still supports PHP 7.0,
    // where "void" is interpreted as a class name instead of a native type.
    $walk = static function (int $managerId, int $level) use (&$walk, &$levels, &$visited, $byManager) {
        foreach ($byManager[$managerId] ?? [] as $person) {
            $id = (int) $person['id'];
            if (isset($visited[$id])) continue;
            $visited[$id] = true;
            $levels[$level][] = $person;
            $walk($id, $level + 1);
        }
    };
    $walk(0, 1);
    foreach ($people as $person) {
        $id = (int) $person['id'];
        if (!isset($visited[$id])) $levels[1][] = $person;
    }
    ksort($levels);
    return $levels;
}

/** Generate a dependency-free, landscape organogram PDF. */
function gt_org_native_pdf(array $levels, array $shiftStats, string $filterText, string $generatedAt): string
{
    $w = 1191.0; $h = 842.0; $margin = 30.0;
    $escape = static function (string $value): string {
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?: trim($value);
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $value);
            if ($converted !== false) $value = $converted;
        }
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);
    };
    $fit = static function (string $value, int $limit): string {
        if (function_exists('mb_strimwidth')) return mb_strimwidth(trim($value), 0, $limit, '...', 'UTF-8');
        return strlen($value) > $limit ? substr($value, 0, $limit - 3) . '...' : $value;
    };
    $text = static function (float $x, float $y, string $value, float $size = 8, bool $bold = false, string $color = '.13 .14 .16') use ($escape): string {
        return sprintf("BT /F%d %.2F Tf %s rg %.2F %.2F Td (%s) Tj ET\n", $bold ? 2 : 1, $size, $color, $x, $y, $escape($value));
    };
    $content = "1 1 1 rg 0 0 {$w} {$h} re f\n";
    $content .= "0.08 0.09 0.10 rg 30 770 142 42 re f\n" . $text(40, 782, 'TISSER', 23, true, '1 1 1');
    $content .= $text(1015, 805, 'ESTRUTURA DA EMPRESA', 7, true, '.35 .68 .25');
    $content .= $text(1015, 786, 'Organograma', 17, true);
    $content .= $text(1015, 771, 'Gerado em ' . $generatedAt, 7, false, '.38 .42 .47');
    $content .= ".12 .13 .14 RG 1 w 30 748 m 1161 748 l S\n";
    $content .= $text(30, 733, $filterText, 7, false, '.38 .42 .47');

    $stats = array_values($shiftStats);
    if ($stats) {
        $gap = 8.0; $cardW = ($w - 2 * $margin - $gap * (count($stats) - 1)) / count($stats);
        foreach ($stats as $i => $stat) {
            $x = $margin + $i * ($cardW + $gap); $daily = (float) ($stat['fte'] ?? 0) * 8;
            $content .= ".85 .87 .89 RG .7 w {$x} 696 {$cardW} 34 re S\n";
            $accent = $i % 2 ? '.35 .68 .25' : '.18 .41 .63';
            $content .= "{$accent} rg {$x} 696 4 34 re f\n";
            $content .= $text($x + 10, 718, (string) ($stat['schedule'] ?? 'Turno'), 7, true, $accent);
            $summary = (int) ($stat['people'] ?? 0) . ' pessoas | ' . number_format($daily, 0, ',', '.') . ' h/dia';
            $content .= $text($x + 10, 706, $summary, 7.5, true);
        }
    }

    $y = 670.0;
    foreach ($levels as $level => $people) {
        if (!$people) continue;
        $count = count($people); $cols = min(5, max(1, $count)); $gap = 8.0;
        $cardW = ($w - 2 * $margin - $gap * ($cols - 1)) / $cols;
        $rows = (int) ceil($count / $cols); $cardH = 63.0;
        $needed = 24 + $rows * ($cardH + 8);
        if ($y - $needed < 42) break;
        $label = $level === 1 ? 'DIREÇÃO' : 'NÍVEL ' . $level;
        $content .= ".94 .95 .95 rg 30 " . ($y - 2) . " 54 13 re f\n" . $text(36, $y + 1, $label, 6.5, true, '.38 .42 .47');
        $y -= 18;
        foreach (array_values($people) as $i => $person) {
            $col = $i % $cols; $row = intdiv($i, $cols); $x = $margin + $col * ($cardW + $gap); $cy = $y - ($row + 1) * $cardH - $row * 8;
            $department = trim((string) ($person['department'] ?? '')) ?: 'Sem departamento';
            $role = trim((string) ($person['job_title'] ?? $person['title'] ?? $person['profession'] ?? '')) ?: 'Função por preencher';
            $manager = trim((string) ($person['manager_name'] ?? $person['manager_name_resolved'] ?? '')) ?: 'Topo da estrutura';
            $schedule = trim((string) ($person['schedule_name'] ?? '')) ?: 'Sem turno';
            $hours = substr((string) ($person['start_time'] ?? ''), 0, 5) . '-' . substr((string) ($person['end_time'] ?? ''), 0, 5);
            $content .= ".86 .88 .90 RG .7 w {$x} {$cy} {$cardW} {$cardH} re S\n.35 .68 .25 rg {$x} {$cy} 4 {$cardH} re f\n";
            $content .= $text($x + 10, $cy + 47, $fit((string) ($person['name'] ?? ''), 32), 8.5, true);
            $content .= $text($x + 10, $cy + 34, $fit($role, 38), 7, false, '.34 .38 .42');
            $content .= $text($x + 10, $cy + 22, $fit($schedule . ' | ' . $hours . ' | ' . (int) ($person['capacity_percent'] ?? 100) . '%', 43), 6.5, false, '.38 .42 .47');
            $content .= $text($x + 10, $cy + 11, $fit('Reporta a: ' . $manager, 43), 6.3, true, '.38 .42 .47');
            $content .= $text($x + $cardW - 10 - min(70, strlen($department) * 4), $cy + 3, $fit(strtoupper($department), 18), 5.8, true, '.35 .68 .25');
        }
        $y -= $rows * ($cardH + 8) + 13;
        $content .= ".86 .88 .90 RG .6 w 30 {$y} m 1161 {$y} l S\n"; $y -= 21;
    }
    $content .= $text(30, 22, 'TISSER  |  https://tisser.pt', 6.5, true);
    $content .= $text(1040, 22, 'Documento interno - Organograma', 6.2, false, '.38 .42 .47');

    $objects = [
        "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n",
        "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n",
        "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$w} {$h}] /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R >>\nendobj\n",
        "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>\nendobj\n",
        "5 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>\nendobj\n",
        "6 0 obj\n<< /Length " . strlen($content) . " >>\nstream\n{$content}endstream\nendobj\n",
    ];
    $pdf = "%PDF-1.4\n"; $offsets = [0];
    foreach ($objects as $object) { $offsets[] = strlen($pdf); $pdf .= $object; }
    $xref = strlen($pdf); $pdf .= "xref\n0 7\n0000000000 65535 f \n";
    for ($i = 1; $i <= 6; $i++) $pdf .= sprintf('%010d 00000 n ', $offsets[$i]) . "\n";
    return $pdf . "trailer\n<< /Size 7 /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
}
