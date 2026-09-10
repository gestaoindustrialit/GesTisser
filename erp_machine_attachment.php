<?php
require_once __DIR__ . '/hr_organization_lib.php';
require_once __DIR__ . '/app/Services/MachineAttachment.php';

require_login();
$user = current_user($pdo) ?: [];
if (!gt_is_erp_allowed($pdo, $user)) {
    http_response_code(403);
    exit('Acesso reservado ao ERP.');
}

$attachmentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$attachmentId || $attachmentId < 1) {
    http_response_code(404);
    exit('Documento não encontrado.');
}

$stmt = $pdo->prepare(
    'SELECT a.* FROM erp_machine_attachments a '
    . 'INNER JOIN erp_machines m ON m.id = a.machine_id '
    . 'WHERE a.id = ? AND a.deleted_at IS NULL AND m.deleted_at IS NULL LIMIT 1'
);
$stmt->execute([$attachmentId]);
$attachment = $stmt->fetch(PDO::FETCH_ASSOC);
$absolutePath = $attachment
    ? gt_machine_attachment_path(__DIR__, (string) ($attachment['file_path'] ?? ''))
    : '';

if (!$attachment || $absolutePath === '') {
    http_response_code(404);
    exit('Documento não encontrado.');
}

$mime = gt_machine_attachment_mime($absolutePath, (string) ($attachment['original_name'] ?? ''));
if ($mime === '') {
    $mime = 'application/octet-stream';
}
$fileName = trim((string) ($attachment['original_name'] ?? 'documento')) ?: 'documento';
$asciiName = preg_replace('/[^A-Za-z0-9._-]/', '_', $fileName) ?: 'documento';

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($absolutePath));
header('Content-Disposition: inline; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($fileName));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
readfile($absolutePath);
exit;
