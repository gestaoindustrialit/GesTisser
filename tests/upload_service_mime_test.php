<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Services/UploadService.php';

$cases = [
    ['drawing.pdf', "%PDF-1.7\nexample", 'application/pdf'],
    ['photo.jpg', "\xFF\xD8\xFF\xE0example", 'image/jpeg'],
    ['photo.png', "\x89PNG\r\n\x1A\nexample", 'image/png'],
    ['photo.webp', 'RIFF1234WEBPexample', 'image/webp'],
];

foreach ($cases as $case) {
    $path = tempnam(sys_get_temp_dir(), 'gt_upload_mime_');
    file_put_contents($path, $case[1]);
    $actual = UploadService::detectMime($path);
    unlink($path);
    if ($actual !== $case[2]) {
        throw new RuntimeException($case[0] . ': esperado ' . $case[2] . ', obtido ' . $actual);
    }
}

$source = (string) file_get_contents(dirname(__DIR__) . '/app/Services/UploadService.php');
if (strpos($source, "function_exists('finfo_open')") === false) {
    throw new RuntimeException('A utilização de fileinfo não está protegida para servidores sem a extensão.');
}
if (strpos($source, "function_exists('finfo_open')") > strpos($source, '$finfo = finfo_open(')) {
    throw new RuntimeException('A chamada a finfo_open ocorre antes da verificação de compatibilidade.');
}

echo "Deteção MIME de uploads validada sem dependência obrigatória de fileinfo.\n";
