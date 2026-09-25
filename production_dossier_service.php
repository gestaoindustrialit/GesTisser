<?php
declare(strict_types=1);

// Compatibility entry point kept for installations that reference the service
// from the project root. The implementation has a single canonical location so
// loading both legacy and application paths cannot redeclare the class.
if (!class_exists('ProductionDossierService', false)) {
    require_once __DIR__ . '/app/Services/ProductionDossierService.php';
}
