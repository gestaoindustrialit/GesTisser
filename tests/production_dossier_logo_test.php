<?php
require_once __DIR__ . '/../app/Services/ReportLogo.php';
$controller = (string)file_get_contents(__DIR__ . '/../production_dossier.php');
$template = (string)file_get_contents(__DIR__ . '/../production_dossier_print.php');
function dossier_logo_check($condition, $message)
{
    if (!$condition) throw new RuntimeException($message);
}
dossier_logo_check(strpos($controller, "app_setting(\$pdo,'logo_report_dark','')") !== false, 'O dossier não usa a configuração de logótipo dos relatórios.');
dossier_logo_check(strpos($controller, 'ReportLogo::source($configuredPath, __DIR__)') !== false, 'O dossier não usa o conversor verificado.');
dossier_logo_check(strpos($template, '<img src="<?=h($productionCompanyLogo)?>"') !== false, 'O template não apresenta o logótipo convertido.');
dossier_logo_check(strpos($controller, "include __DIR__.'/production_dossier_print.php'") !== false, 'A impressão deve usar o template do browser.');

$root = sys_get_temp_dir() . '/dossier-logo-' . bin2hex(random_bytes(6));
mkdir($root, 0700);
$outside = tempnam(sys_get_temp_dir(), 'dossier-outside-');
file_put_contents($outside, 'outside-logo');
try {
    foreach (['png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','webp'=>'image/webp','svg'=>'image/svg+xml'] as $extension=>$mime) {
        $name = 'logo.' . $extension;
        file_put_contents($root . '/' . $name, 'logo-' . $extension);
        $source = ReportLogo::source($name, $root);
        dossier_logo_check(strpos($source, 'data:' . $mime . ';base64,') === 0, 'Tipo de imagem incorreto: ' . $extension);
        dossier_logo_check(base64_decode(substr($source, strpos($source, ',') + 1), true) === 'logo-' . $extension, 'Conteúdo do logótipo alterado.');
    }
    foreach (['https://example.com/logo.png','http://example.com/logo.svg'] as $url) {
        dossier_logo_check(ReportLogo::source($url, $root) === $url, 'A URL do logótipo não foi preservada.');
    }
    dossier_logo_check(ReportLogo::source('', $root) === '', 'A ausência de configuração deve devolver vazio.');
    dossier_logo_check(ReportLogo::source('missing.png', $root) === '', 'Um ficheiro inexistente deve devolver vazio.');
    dossier_logo_check(ReportLogo::source('../' . basename($outside), $root) === '', 'Um caminho fora da aplicação não deve ser incorporado.');
} finally {
    foreach (glob($root . '/*') as $path) unlink($path);
    rmdir($root);
    unlink($outside);
}
echo "production_dossier_logo_test: OK\n";
