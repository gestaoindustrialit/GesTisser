<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$entryPoints = ['erp.php', 'production_dossier.php'];
$service = $root . '/production_dossier_service.php';
$applicationEntryPoint = $root . '/app/Services/ProductionDossierService.php';

if (!is_file($service) || !is_readable($service) || !is_file($applicationEntryPoint) || !is_readable($applicationEntryPoint)) {
    fwrite(STDERR, "FAIL - o serviço do Dossier de Produção não está no pacote raiz.\n");
    exit(1);
}

foreach ($entryPoints as $entryPoint) {
    $source = file_get_contents($root . '/' . $entryPoint);
    if ($source === false || strpos($source, "__DIR__ . '/production_dossier_service.php'") === false) {
        fwrite(STDERR, 'FAIL - ' . $entryPoint . " não referencia o serviço distribuído.\n");
        exit(1);
    }
}

// Either path may be loaded first. Both must resolve to the root implementation
// without redeclaring the class or making the root entry point depend on an
// application-directory file that may be absent during a partial deployment.
$rootSource = (string) file_get_contents($service);
if (strpos($rootSource, '/app/Services/') !== false) {
    fwrite(STDERR, "FAIL - o serviço raiz depende de um ficheiro que pode não ser publicado.\n");
    exit(1);
}
require_once $applicationEntryPoint;
require_once $service;
if (!class_exists('ProductionDossierService', false)) {
    fwrite(STDERR, "FAIL - ProductionDossierService não foi carregado.\n");
    exit(1);
}

echo "Dossier packaging validation passed.\n";
