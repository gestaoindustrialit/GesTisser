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
$erpSource = file_get_contents(dirname(__DIR__) . '/erp.php');
if (strpos($erpSource, "=== 'machine_attachment'") === false) {
    throw new RuntimeException('O front controller do ERP não encaminha os pedidos de anexos.');
}
$machinesSource = file_get_contents(dirname(__DIR__) . '/erp_machines.php');
if (strpos($machinesSource, "'erp_machine_attachment.php?id='") !== false) {
    throw new RuntimeException('A interface ainda referencia a rota PHP que não é publicada pelo alojamento.');
}

unlink($pdfPath);
rmdir($uploadRoot . '/storage/uploads/machines');
rmdir($uploadRoot . '/storage/uploads');
rmdir($uploadRoot . '/storage');
rmdir($uploadRoot);
echo "Entrega segura de anexos de máquinas validada.\n";
