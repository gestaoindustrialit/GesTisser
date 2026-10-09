<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$services = [
    'ArticleDocument' => $root . '/article_document.php',
    'ProductionDossierService' => $root . '/production_dossier_service.php',
    'MaterialProfile' => $root . '/material_profile_service.php',
];

foreach ($services as $class => $file) {
    if (!is_file($file) || !is_readable($file)) {
        throw new RuntimeException('Entry point raiz em falta: ' . basename($file));
    }
    $source = (string) file_get_contents($file);
    if (strpos($source, '/app/Services/') !== false) {
        throw new RuntimeException('O entry point raiz não pode depender de app/Services: ' . basename($file));
    }
    require_once $file;
    if (!class_exists($class, false)) {
        throw new RuntimeException('Classe não carregada pelo entry point raiz: ' . $class);
    }
}

$materialPage = (string) file_get_contents($root . '/erp_material.php');
if (strpos($materialPage, "__DIR__.'/material_profile_service.php'") === false) {
    throw new RuntimeException('A ficha de material não utiliza o entry point raiz de MaterialProfile.');
}

foreach (['erp.php', 'erp_technical_sheet.php', 'production_dossier.php'] as $page) {
    $source = (string) file_get_contents($root . '/' . $page);
    if (strpos($source, "__DIR__ . '/article_document.php'") === false) {
        throw new RuntimeException($page . ' não utiliza o entry point raiz de ArticleDocument.');
    }
}

echo "Root service entry-point validation passed.\n";
