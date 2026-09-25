<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$entryPoints = ['erp.php', 'production_dossier.php'];
$service = $root . '/production_dossier_service.php';
$canonicalService = $root . '/app/Services/ProductionDossierService.php';

if (!is_file($service) || !is_readable($service) || !is_file($canonicalService) || !is_readable($canonicalService)) {
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

// Production may load the canonical service before a legacy entry point. Load
// both paths here to guard against the class redeclaration that this caused.
require_once $canonicalService;
require_once $service;
if (!class_exists('ProductionDossierService', false)) {
    fwrite(STDERR, "FAIL - ProductionDossierService não foi carregado.\n");
    exit(1);
}

echo "Dossier packaging validation passed.\n";
