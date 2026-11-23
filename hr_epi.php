<?php
require_once __DIR__ . '/hr_organization_lib.php';
require_once __DIR__ . '/app/Services/HrEpiSharePoint.php';
require_once __DIR__ . '/app/Services/HrEpiPdf.php';

require_login();
header('Cache-Control: private, no-store');
$actor = current_user($pdo);
$userId = (int) $actor['id'];
$epi = new HrEpi($pdo);
if (!(int) $epi->query('SELECT is_active FROM users WHERE id=?', [$userId])->fetchColumn()) {
    http_response_code(403);
    exit('Acesso indisponível.');
}
$canManage = can_access_hr_module($pdo, $userId) || gt_erp_user_can($pdo, $actor, 'hr.epi.manage');
$canDeliver = $canManage || gt_erp_user_can($pdo, $actor, 'hr.epi.deliver');

$forbidden = static function () {
    http_response_code(403);
    exit('Acesso indisponível.');
};

$tabs = ['history' => $canManage ? 'Histórico' : 'Os meus EPI'];
if ($canDeliver) {
    $tabs = ['new' => 'Nova Entrega'] + $tabs;
}
if ($canManage) {
    $tabs = ['dashboard' => 'Dashboard'] + $tabs + ['catalog' => 'Catálogo EPI'];
}
$tab = $_GET['tab'] ?? key($tabs);
if (!is_string($tab) || !isset($tabs[$tab])) {
    $forbidden();
}

$epi->migrate();
$operationId = is_string($_GET['operation'] ?? null) ? $_GET['operation'] : '';
$operation = $operationId === '' ? null : $epi->operation($operationId);
$ownsOperation = $operation && (int) $operation['employee_id'] === $userId;
$canConfirm = $operation && $epi->canConfirm($operation, $actor, $canDeliver);
if ($operationId !== '' && (!$operation || (!$canManage && !$canConfirm))) {
    $forbidden();
}

$employeeSql = gt_org_employee_sql('u');
$embed = ($_GET['embed'] ?? '') === '1' && $_SERVER['REQUEST_METHOD'] === 'GET' && $canManage && $tab === 'history' && !$operation
    && is_string($_GET['employee'] ?? null) && preg_match('/^[0-9]{1,10}$/D', $_GET['employee']) && (int) $_GET['employee'] > 0;
if ($embed) {
    header('X-Frame-Options: SAMEORIGIN');
    header('Content-Security-Policy: ' . str_replace("frame-ancestors 'none'", "frame-ancestors 'self'", app_config('security.csp')));
} else {
    header('X-Frame-Options: DENY');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_abort(true);
    $action = $_POST['action'] ?? '';
    $return = 'hr_epi.php?tab=' . $tab;
    try {
        switch ($action) {
            case 'catalog':
                if (!$canManage) {
                    $forbidden();
                }
                $return = 'hr_epi.php?tab=catalog';
                $epi->saveCatalog($_POST, $actor);
                flash('epi_success', 'Equipamento guardado.');
                break;
            case 'deliver':
                if (!$canDeliver) {
                    $forbidden();
                }
                $return = 'hr_epi.php?tab=new';
                $employeeId = HrEpi::number($_POST['employee'] ?? '', PHP_INT_MAX);
                if (!$epi->query('SELECT 1 FROM users u WHERE id=? AND ' . $employeeSql, [$employeeId])->fetchColumn()) {
                    throw new HrEpiInputException('Colaborador indisponível.');
                }
                $items = $_POST['items'] ?? null;
                if (!is_array($items)) {
                    throw new HrEpiInputException('Adicione pelo menos um equipamento.');
                }
                $operationId = $epi->deliver($employeeId, $items, $actor);
                $return = 'hr_epi.php?tab=history&operation=' . $operationId;
                flash('epi_success', 'Entrega guardada. A confirmação pode ser feita agora ou mais tarde.');
                break;
            case 'confirm':
                if (!$canConfirm) {
                    $forbidden();
                }
                $return = 'hr_epi.php?tab=history&operation=' . $operationId;
                $epi->confirm($operationId, $actor, $canDeliver, ($_POST['accept'] ?? '') === '1'
                    && ($_POST['declaration_version'] ?? '') === HrEpi::DECLARATION_VERSION, HrEpi::field($_POST, 'pin', 6));
                flash('epi_success', 'Receção confirmada.');
                break;
            case 'return':
            case 'correction':
                if (!$canManage) {
                    $forbidden();
                }
                $return = 'hr_epi.php?tab=history' . ($operation ? '&operation=' . $operationId : '');
                $epi->recordEvent(HrEpi::field($_POST, 'item'), $action, HrEpi::field($_POST, 'notes', 2000),
                    $action === 'return' ? HrEpi::number($_POST['quantity'] ?? '', 9999) : null, $actor);
                flash('epi_success', $action === 'return' ? 'Devolução registada.' : 'Retificação registada.');
                break;
            case 'sync':
                if (!$canManage) {
                    $forbidden();
                }
                if (!app_config('external_services_enabled', false)) {
                    throw new HrEpiInputException('Os serviços externos estão desativados neste ambiente.');
                }
                $count = (new HrEpiSharePoint($pdo))->run(true, $userId);
                flash('epi_success', 'Registos sincronizados neste lote: ' . $count . '. Consulte o estado de cada registo.');
                break;
            default:
                http_response_code(400);
                exit('Operação inválida.');
        }
    } catch (HrEpiInputException $e) {
        flash('epi_error', $e->getMessage());
    }
    redirect($return);
}

$filters = [];
try {
    foreach (['employee', 'equipment', 'category', 'from', 'to'] as $filterName) {
        $filters[$filterName] = HrEpi::field($_GET, $filterName);
    }
    if ($filters['employee'] !== '') {
        $filters['employee'] = HrEpi::number($filters['employee'], PHP_INT_MAX);
    }
    foreach (['from', 'to'] as $key) {
        if ($filters[$key] !== '') {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $filters[$key]);
            if (!$date || $date->format('Y-m-d') !== $filters[$key]) {
                throw new HrEpiInputException('Data inválida.');
            }
        }
    }
} catch (HrEpiInputException $e) {
    http_response_code(400);
    exit('Filtros inválidos.');
}
if (!$canManage) {
    $filters['employee'] = $userId;
}
if ($operation) {
    $rows = $epi->history(['operation' => $operationId]);
} elseif ($tab === 'dashboard' || $tab === 'history') {
    $rows = $epi->history($filters);
} else {
    $rows = [];
}
$pdfType = $_GET['pdf'] ?? '';
if ($pdfType !== '') {
    $proof = $pdfType === 'proof' && $operation && $operation['confirmed_at'] && ($canManage || $ownsOperation);
    $history = $pdfType === 'history' && !$operation && !empty($filters['employee']) && $tab === 'history';
    if (!$proof && !$history) {
        $forbidden();
    }
    $filename = $proof ? 'comprovativo-epi-' . $operation['proof_id'] . '.pdf' : 'historico-epi.pdf';
    $pdf = HrEpiPdf::create($rows, $proof ? $operation : null);
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $filename . '"');
    echo $pdf;
    exit;
}

$employees = [];
if ($canDeliver && !$operation && $tab === 'new') {
    $employees = $epi->query('SELECT id,name,user_number FROM users u WHERE is_active=1 AND ' . $employeeSql . ' ORDER BY name COLLATE NOCASE')->fetchAll(PDO::FETCH_ASSOC);
} elseif ($canManage && !$operation && in_array($tab, ['dashboard', 'history'], true)) {
    $employees = $epi->query('SELECT id,name,user_number FROM users u WHERE ' . $employeeSql . ' ORDER BY name COLLATE NOCASE')->fetchAll(PDO::FETCH_ASSOC);
}
$catalog = [];
if (!$operation && !$canManage && $tab === 'history') {
    $catalog = $epi->query('SELECT i.epi_id AS id, MAX(i.name) AS name FROM hr_epi_items i
        JOIN hr_epi_operations o ON o.id=i.operation_id WHERE o.employee_id=? GROUP BY i.epi_id ORDER BY name COLLATE NOCASE',
        [$userId])->fetchAll(PDO::FETCH_ASSOC);
} elseif (!$operation) {
    $catalog = $epi->query("SELECT c.*,q.error,q.revision,q.synced_revision FROM hr_epi_catalog c
        JOIN hr_epi_outbox q ON q.kind='catalog' AND q.entity_id=c.id"
        . ($tab === 'new' ? ' WHERE c.active=1' : '') . ' ORDER BY c.name COLLATE NOCASE')->fetchAll(PDO::FETCH_ASSOC);
}
$events = [];
if ($operation && ($canManage || $ownsOperation)) {
    foreach ($epi->query('SELECT e.* FROM hr_epi_events e JOIN hr_epi_items i ON i.id=e.item_id WHERE i.operation_id=? ORDER BY e.id', [$operationId]) as $event) {
        $events[$event['item_id']][] = $event;
    }
}
$employeeLabel = static function (array $person) {
    return ($person['user_number'] ? $person['user_number'] . ' · ' : '') . $person['name'];
};
$replaceable = static function (array $row) {
    return $row['replacement_date'] && $row['current_cycle'] && $row['returned'] < $row['quantity'];
};
$status = static function (array $row) {
    return $row['confirmed_at'] ? 'Confirmado' : 'Pendente de confirmação';
};
$pageTitle = 'Gestão de EPI';
if ($embed) {
    ?><!doctype html><html lang="pt-PT"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>EPI Entregues</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head><body class="p-2"><?php
} else {
    require __DIR__ . '/partials/header.php';
}
require __DIR__ . '/partials/hr_epi.php';
if ($embed) {
    echo '</body></html>';
} else {
    require __DIR__ . '/partials/footer.php';
}
