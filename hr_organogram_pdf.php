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
$where[] = gt_org_employee_sql('u');
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

$sql = 'SELECT u.*, s.name AS schedule_name, s.start_time, s.end_time, s.second_start_time, s.second_end_time, m.name AS manager_name
    FROM users u
    LEFT JOIN hr_schedules s ON s.id = u.schedule_id
    LEFT JOIN users m ON m.id = u.manager_user_id'
    . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
    . ' ORDER BY COALESCE(u.manager_user_id, 0), COALESCE(u.org_sort_order, 0), u.name COLLATE NOCASE ASC';
$statement = $pdo->prepare($sql);
$statement->execute($parameters);
$people = $statement->fetchAll(PDO::FETCH_ASSOC);

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

$levels = gt_org_levels($people);
$shiftStats = [];
foreach ($people as $person) {
    $key = (int) ($person['schedule_id'] ?? 0);
    $label = trim((string) ($person['schedule_name'] ?? '')) ?: 'Sem turno';
    $start = substr(trim((string) ($person['start_time'] ?? '')), 0, 5);
    $endValue = trim((string) ($person['second_end_time'] ?? '')) ?: trim((string) ($person['end_time'] ?? ''));
    $end = substr($endValue, 0, 5);
    if (!isset($shiftStats[$key])) $shiftStats[$key] = ['schedule' => $label, 'people' => 0, 'fte' => 0.0, 'start' => $start, 'end' => $end];
    $shiftStats[$key]['people']++;
    $shiftStats[$key]['fte'] += (int) ($person['capacity_percent'] ?? 100) / 100;
    if ($start !== '' && ($shiftStats[$key]['start'] === '' || $start < $shiftStats[$key]['start'])) $shiftStats[$key]['start'] = $start;
    if ($end !== '' && ($shiftStats[$key]['end'] === '' || $end > $shiftStats[$key]['end'])) $shiftStats[$key]['end'] = $end;
}
$configuredLogo = trim((string) app_setting($pdo, 'logo_report_dark', ''));
$logoPath = gt_org_brand_logo_path($configuredLogo, __DIR__);
if ($logoPath === '') $logoPath = gt_org_brand_logo_path(trim((string) app_setting($pdo, 'logo_navbar_light', '')), __DIR__);
$companyName = trim((string) app_setting($pdo, 'company_name', 'GesTisser')) ?: 'GesTisser';
$pdf = gt_org_native_pdf($levels, $shiftStats, $filterText, date('d/m/Y H:i'), $logoPath, $companyName);

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="organograma-' . date('Y-m-d') . '.pdf"');
header('Content-Length: ' . strlen($pdf));
header('X-Content-Type-Options: nosniff');
echo $pdf;
exit;
