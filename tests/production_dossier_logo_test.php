<?php
$controller = (string) file_get_contents(__DIR__ . '/../production_dossier.php');
$technicalSheet = (string) file_get_contents(__DIR__ . '/../erp_technical_sheet.php');
$template = (string) file_get_contents(__DIR__ . '/../production_dossier_print.php');

function dossier_logo_check($condition, $message)
{
    if (!$condition) throw new RuntimeException($message);
}

dossier_logo_check(strpos($controller, "\$productionCompanyLogo=pd_logo_src((string)app_setting(\$pdo,'logo_report_dark',''))") !== false, 'O dossier não usa logo_report_dark diretamente.');
dossier_logo_check(strpos($controller, 'logo_navbar_light') === false, 'O dossier não deve usar outra configuração de logótipo.');
dossier_logo_check(strpos($controller, "'webp' => 'image/webp'") !== false && strpos($controller, "'svg' => 'image/svg+xml'") !== false, 'Os formatos da ficha técnica não estão todos suportados.');
dossier_logo_check(strpos($controller, "__DIR__ . '/' . ltrim(\$configuredPath, '/')") !== false, 'O caminho local não segue a implementação da ficha técnica.');
dossier_logo_check(strpos($template, '<img src="<?=h($productionCompanyLogo)?>"') !== false, 'O template deixou de usar o valor convertido.');
dossier_logo_check(strpos($technicalSheet, 'function technical_sheet_logo_src') !== false, 'A implementação funcional da ficha técnica foi alterada.');

$functionStart = strpos($controller, 'function pd_logo_src');
$functionEnd = strpos($controller, "\n\$documentNumberStmt=", $functionStart);
dossier_logo_check($functionStart !== false && $functionEnd !== false, 'Não foi possível localizar o conversor do dossier.');
eval(substr($controller, $functionStart, $functionEnd - $functionStart));

foreach (['png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','webp'=>'image/webp','svg'=>'image/svg+xml'] as $extension=>$mime) {
    $relative = '.dossier-logo.' . $extension;
    $absolute = __DIR__ . '/.dossier-logo.' . $extension;
    file_put_contents($absolute, 'logo-' . $extension);
    try {
        $source = pd_logo_src($relative);
        dossier_logo_check(strpos($source, 'data:' . $mime . ';base64,') === 0, 'Data URI incorreto para ' . $extension . '.');
    } finally {
        @unlink($absolute);
    }
}

dossier_logo_check(pd_logo_src('https://example.com/logo.png') === 'https://example.com/logo.png', 'A URL HTTPS não foi preservada.');
dossier_logo_check(pd_logo_src('tests/inexistente.png') === '', 'Um caminho inexistente deve devolver vazio.');
echo "production_dossier_logo_test: OK\n";
