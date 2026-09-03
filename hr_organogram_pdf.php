<?php
require_once __DIR__ . '/hr_organization_lib.php';

require_login();
gt_run_org_migrations($pdo);

$userId = (int) ($_SESSION['user_id'] ?? 0);
if (!gt_is_hr_allowed($pdo, $userId)) {
    http_response_code(403);
    exit('Acesso reservado a RH.');
}

$department = trim((string) ($_GET['department'] ?? ''));
$scheduleId = (int) ($_GET['schedule_id'] ?? 0);
$query = trim((string) ($_GET['q'] ?? ''));
$showInactive = (int) ($_GET['inactive'] ?? 0) === 1;

$where = [];
$parameters = [];
if (!$showInactive) {
    $where[] = 'COALESCE(u.is_active, 1) = 1';
}
if ($department !== '') {
    $where[] = 'u.department = ?';
    $parameters[] = $department;
}
if ($scheduleId > 0) {
    $where[] = 'u.schedule_id = ?';
    $parameters[] = $scheduleId;
}
if ($query !== '') {
    $where[] = '(u.name LIKE ? OR u.job_title LIKE ? OR u.title LIKE ? OR u.profession LIKE ? OR u.access_profile LIKE ? OR u.department LIKE ?)';
    for ($index = 0; $index < 6; $index++) {
        $parameters[] = '%' . $query . '%';
    }
}

$sql = 'SELECT u.*, s.name AS schedule_name, s.start_time, s.end_time, m.name AS manager_name
    FROM users u
    LEFT JOIN hr_schedules s ON s.id = u.schedule_id
    LEFT JOIN users m ON m.id = u.manager_user_id'
    . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
    . ' ORDER BY COALESCE(u.manager_user_id, 0), COALESCE(u.org_sort_order, 0), u.name COLLATE NOCASE ASC';
$statement = $pdo->prepare($sql);
$statement->execute($parameters);
$people = $statement->fetchAll(PDO::FETCH_ASSOC);

$roleLabel = static function (array $person): string {
    foreach (['job_title', 'title', 'profession', 'access_profile'] as $field) {
        $value = trim((string) ($person[$field] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }
    return 'Função por preencher';
};
$departmentLabel = static function (array $person): string {
    $value = trim((string) ($person['department'] ?? ''));
    return $value !== '' ? $value : 'Sem departamento';
};
$timeLabel = static function ($value): string {
    $value = trim((string) $value);
    return $value === '' ? '--:--' : substr($value, 0, 5);
};

$filterLabels = [];
if ($department !== '') {
    $filterLabels[] = 'Departamento: ' . $department;
}
if ($scheduleId > 0 && !empty($people[0]['schedule_name'])) {
    $filterLabels[] = 'Turno: ' . (string) $people[0]['schedule_name'];
}
if ($query !== '') {
    $filterLabels[] = 'Pesquisa: ' . $query;
}
if ($showInactive) {
    $filterLabels[] = 'Inclui utilizadores inativos';
}
$filterText = $filterLabels ? implode(' · ', $filterLabels) : 'Todos os colaboradores ativos';

$cards = '';
$fallbackLines = ['GESTISSER - ORGANOGRAMA', $filterText, 'Gerado em ' . date('d/m/Y H:i'), ''];
foreach ($people as $person) {
    $name = (string) ($person['name'] ?? '');
    $role = $roleLabel($person);
    $personDepartment = $departmentLabel($person);
    $manager = trim((string) ($person['manager_name'] ?? '')) ?: 'Topo da estrutura';
    $schedule = trim((string) ($person['schedule_name'] ?? '')) ?: 'Sem turno';
    $hours = $timeLabel($person['start_time'] ?? '') . '-' . $timeLabel($person['end_time'] ?? '');
    $capacity = (int) ($person['capacity_percent'] ?? 100);

    $cards .= '<div class="person"><div class="department">' . h($personDepartment) . '</div>'
        . '<h2>' . h($name) . '</h2><div class="role">' . h($role) . '</div>'
        . '<table><tr><th>Reporta a</th><td>' . h($manager) . '</td></tr>'
        . '<tr><th>Turno</th><td>' . h($schedule . ' · ' . $hours) . '</td></tr>'
        . '<tr><th>Capacidade</th><td>' . $capacity . '%</td></tr></table></div>';
    $fallbackLines[] = $name . ' | ' . $role . ' | ' . $personDepartment;
    $fallbackLines[] = '  Reporta a: ' . $manager . ' | ' . $schedule . ' ' . $hours . ' | ' . $capacity . '%';
}
if ($people === []) {
    $cards = '<p class="empty">Não existem pessoas para os filtros selecionados.</p>';
    $fallbackLines[] = 'Não existem pessoas para os filtros selecionados.';
}

$html = '<!doctype html><html lang="pt"><head><meta charset="utf-8"><style>
    @page { margin: 13mm; } body { color: #24272d; font-family: sans-serif; font-size: 10pt; }
    header { border-bottom: 3px solid #2d69a1; margin-bottom: 7mm; padding-bottom: 4mm; }
    .eyebrow, .department { color: #58ad3e; font-size: 7pt; font-weight: bold; letter-spacing: 1.5px; text-transform: uppercase; }
    h1 { font-size: 22pt; margin: 1mm 0; } .meta { color: #68727e; font-size: 8pt; }
    .summary { background: #f3f6f8; border: 1px solid #dce2e8; margin-bottom: 5mm; padding: 3mm; }
    .person { border: 1px solid #d7dde4; border-left: 4px solid #2d69a1; border-radius: 4px; display: inline-block; margin: 0 2% 4mm 0; padding: 4mm; vertical-align: top; width: 43%; page-break-inside: avoid; }
    h2 { font-size: 12pt; margin: 1mm 0; } .role { color: #5f6974; margin-bottom: 3mm; }
    table { border-collapse: collapse; width: 100%; } th, td { border-top: 1px solid #e4e8ed; padding: 1.5mm 0; text-align: left; }
    th { color: #68727e; font-size: 7pt; text-transform: uppercase; width: 30%; } .empty { color: #68727e; }
    footer { color: #68727e; font-size: 7pt; margin-top: 4mm; text-align: right; }
    </style></head><body><header><div class="eyebrow">Pessoas e responsabilidades</div><h1>Organograma</h1><div class="meta">' . h($filterText) . '</div></header>'
    . '<div class="summary"><strong>' . count($people) . '</strong> ' . (count($people) === 1 ? 'pessoa apresentada' : 'pessoas apresentadas') . '</div>'
    . $cards . '<footer>Gerado em ' . date('d/m/Y H:i') . '</footer></body></html>';

$pdf = taskforce_generate_pdf_from_html($html);
if (!is_string($pdf) || strncmp($pdf, '%PDF', 4) !== 0) {
    $pdf = taskforce_generate_basic_pdf($fallbackLines);
}

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="organograma-' . date('Y-m-d') . '.pdf"');
header('Content-Length: ' . strlen($pdf));
header('X-Content-Type-Options: nosniff');
echo $pdf;
exit;
