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
