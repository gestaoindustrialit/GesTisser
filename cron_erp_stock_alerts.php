<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/erp_migrations.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Execução permitida apenas por linha de comandos.');
}

erp_run_phase1_migrations($pdo);
$sql = 'SELECT rm.id,rm.code,rm.description,rm.alert_email,rm.min_stock,rm.reorder_point,
        COALESCE(SUM(sb.physical_qty-sb.reserved_qty-sb.blocked_qty),0) available_qty
        FROM erp_raw_materials rm
        LEFT JOIN erp_stock_balances sb ON sb.item_type="raw_material" AND sb.item_id=rm.id
        WHERE rm.status="Ativo" AND rm.alert_enabled=1 AND TRIM(COALESCE(rm.alert_email,""))<>""
        GROUP BY rm.id
        HAVING available_qty <= CASE WHEN rm.reorder_point>0 THEN rm.reorder_point ELSE rm.min_stock END';
$materials = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
$alreadySent = $pdo->prepare('SELECT 1 FROM erp_stock_alert_log WHERE raw_material_id=? AND delivery_status="Enviado" AND date(created_at)=date("now") LIMIT 1');
$log = $pdo->prepare('INSERT INTO erp_stock_alert_log(raw_material_id,available_qty,threshold_qty,recipient_email,delivery_status,error_message) VALUES (?,?,?,?,?,?)');
$sent = $failed = 0;

foreach ($materials as $material) {
    $alreadySent->execute([(int) $material['id']]);
    if ($alreadySent->fetchColumn()) { continue; }
    $threshold = (float) $material['reorder_point'] > 0 ? (float) $material['reorder_point'] : (float) $material['min_stock'];
    $subject = '[gesTISSER] Reposição necessária: ' . $material['code'];
    $body = "A matéria-prima {$material['code']} — {$material['description']} atingiu o nível de reposição.\n\n"
        . "Disponível: {$material['available_qty']}\nLimite: {$threshold}\n\nConsulte o ERP para planear a compra.";
    $headers = "Content-Type: text/plain; charset=UTF-8\r\n";
    $ok = @mail((string) $material['alert_email'], $subject, $body, $headers);
    $log->execute([(int) $material['id'], (float) $material['available_qty'], $threshold, $material['alert_email'], $ok ? 'Enviado' : 'Falhou', $ok ? null : 'A função mail() não aceitou a mensagem.']);
    $ok ? $sent++ : $failed++;
}

echo "Alertas de stock: {$sent} enviado(s), {$failed} falhado(s).\n";
exit($failed > 0 ? 1 : 0);
