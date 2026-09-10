<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Services/MachineAttachment.php';

$cases = [
    ['manual.pdf', "%PDF-1.7\nexample", 'application/pdf'],
    ['photo.png', "\x89PNG\r\n\x1A\nexample", 'image/png'],
    ['sheet.xlsx', "PK\x03\x04example", 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
];

foreach ($cases as $case) {
    $path = tempnam(sys_get_temp_dir(), 'gt_mime_');
    file_put_contents($path, $case[1]);
    $actual = gt_machine_attachment_mime($path, $case[0]);
    unlink($path);
    if ($actual !== $case[2]) {
        throw new RuntimeException($case[0] . ': esperado ' . $case[2] . ', obtido ' . $actual);
    }
}

$path = tempnam(sys_get_temp_dir(), 'gt_mime_');
file_put_contents($path, "<?php echo 'not a PDF';");
$actual = gt_machine_attachment_mime($path, 'unsafe.pdf');
unlink($path);
if ($actual === 'application/pdf') {
    throw new RuntimeException('Um ficheiro inválido foi aceite apenas pela extensão.');
}

echo "Deteção MIME de anexos de máquinas validada.\n";

$documentCases = [
    ['MCC_003-DL50-001-CONFORMIDADE-PT.pdf', 'DL50', 'bi-shield-check'],
    ['MCC_003-MAN-001-MANUAL-PT.pdf', 'MAN', 'bi-book'],
    ['MCC_003-SPR-001-SPARES-PT.pdf', 'SPR', 'bi-gear'],
    ['MCC_003-CIR-001-CIRCUITO-ELETRICO-PT.pdf', 'CIR', 'bi-lightning-charge'],
    ['MCC_002-CRT-001-CERTIFICADO-CE-PT.pdf', 'CRT', 'bi-patch-check'],
    ['fotografia frontal.png', 'IMG', 'bi-image'],
];
foreach ($documentCases as $case) {
    $meta = gt_machine_attachment_document_meta($case[0]);
    if ($meta['code'] !== $case[1] || $meta['icon'] !== $case[2]) {
        throw new RuntimeException($case[0] . ': identidade documental incorreta.');
    }
}

$uploadRoot = sys_get_temp_dir() . '/gt_machine_path_' . bin2hex(random_bytes(5));
mkdir($uploadRoot . '/storage/uploads/machines', 0777, true);
$pdfPath = $uploadRoot . '/storage/uploads/machines/manual.pdf';
file_put_contents($pdfPath, "%PDF-1.7\ntest");

if (gt_machine_attachment_path($uploadRoot, 'storage/uploads/machines/manual.pdf') !== realpath($pdfPath)) {
    throw new RuntimeException('Não foi possível resolver um anexo válido.');
}
if (gt_machine_attachment_path($uploadRoot, 'storage/uploads/machines/../../manual.pdf') !== '') {
    throw new RuntimeException('Foi aceite um caminho fora da pasta de anexos.');
}
if (gt_machine_attachment_url(['id' => 42]) !== 'erp.php?page=machine_attachment&id=42') {
    throw new RuntimeException('A rota segura do anexo não foi gerada corretamente.');
}
if (gt_machine_attachment_directory_name(42, 'Máquina Corte / Cose') !== 'Máquina Corte _ Cose__42') {
    throw new RuntimeException('O nome da pasta não acompanha o nome da máquina em segurança.');
}
$namedDirectory = $uploadRoot . '/storage/uploads/machines/' . gt_machine_attachment_directory_name(42, 'Máquina A');
mkdir($namedDirectory, 0777, true);
$namedTarget = gt_machine_attachment_target($namedDirectory, 'Manual utilização.pdf');
if (basename($namedTarget) !== 'Manual utilização.pdf') {
    throw new RuntimeException('O nome original do documento não foi preservado.');
}
file_put_contents($namedTarget, 'first');
if (basename(gt_machine_attachment_target($namedDirectory, 'Manual utilização.pdf')) !== 'Manual utilização (2).pdf') {
    throw new RuntimeException('Os documentos com nomes repetidos não são distinguidos.');
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE erp_machine_attachments (id INTEGER PRIMARY KEY, machine_id INTEGER, original_name TEXT, file_path TEXT, deleted_at TEXT)');
$legacyPath = $uploadRoot . '/storage/uploads/machines/legacy.pdf';
file_put_contents($legacyPath, "%PDF-1.7\nlegacy");
$pdo->exec("INSERT INTO erp_machine_attachments VALUES (1, 7, 'Manual original.pdf', 'storage/uploads/machines/legacy.pdf', NULL)");
gt_machine_relocate_attachments($pdo, $uploadRoot, 7, 'Máquina Renomeada');
$movedRelativePath = (string) $pdo->query('SELECT file_path FROM erp_machine_attachments WHERE id=1')->fetchColumn();
if ($movedRelativePath !== 'storage/uploads/machines/Máquina Renomeada__7/Manual original.pdf'
    || !is_file($uploadRoot . '/' . $movedRelativePath)) {
    throw new RuntimeException('Os documentos não acompanharam a alteração do nome da máquina.');
}
$erpSource = file_get_contents(dirname(__DIR__) . '/erp.php');
if (strpos($erpSource, "=== 'machine_attachment'") === false) {
    throw new RuntimeException('O front controller do ERP não encaminha os pedidos de anexos.');
}
if (strpos($erpSource, "\$requestedPage !== 'machines' && \$requestedPage !== 'machine_attachment'") === false) {
    throw new RuntimeException('As máquinas ainda carregam serviços ERP incompatíveis e desnecessários.');
}
$machinesSource = file_get_contents(dirname(__DIR__) . '/erp_machines.php');
if (strpos($machinesSource, "'erp_machine_attachment.php?id='") !== false) {
    throw new RuntimeException('A interface ainda referencia a rota PHP que não é publicada pelo alojamento.');
}
if (strpos($machinesSource, 'const chunkSize = 512 * 1024;') === false
    || strpos($machinesSource, "searchParams.set('machine_upload', 'chunk')") === false) {
    throw new RuntimeException('O upload segmentado pode ultrapassar os limites comuns do PHP.');
}
if (strpos($machinesSource, 'JSON.parse(responseText)') === false) {
    throw new RuntimeException('O upload não trata respostas não-JSON do servidor.');
}
if (strpos($machinesSource, "modal.addEventListener('hidden.bs.modal'") === false
    || strpos($machinesSource, 'previewReturnsToEditor') === false
    || strpos($machinesSource, 'restoringEditorAfterPreview') === false) {
    throw new RuntimeException('A pré-visualização pode ficar escondida atrás da edição da máquina.');
}
if (strpos($machinesSource, 'URL.createObjectURL(blob)') === false
    || strpos($machinesSource, "fetch(url, { credentials: 'same-origin' })") === false) {
    throw new RuntimeException('A pré-visualização continua dependente de incorporar diretamente a resposta do alojamento.');
}

unlink($pdfPath);
unlink($namedTarget);
rmdir($namedDirectory);
unlink($uploadRoot . '/' . $movedRelativePath);
rmdir(dirname($uploadRoot . '/' . $movedRelativePath));
rmdir($uploadRoot . '/storage/uploads/machines');
rmdir($uploadRoot . '/storage/uploads');
rmdir($uploadRoot . '/storage');
rmdir($uploadRoot);
echo "Entrega segura de anexos de máquinas validada.\n";
