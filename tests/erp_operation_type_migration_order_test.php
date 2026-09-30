<?php
declare(strict_types=1);

$source = (string) file_get_contents(dirname(__DIR__) . '/erp_migrations.php');
$operationColumns = strpos($source, '$operationColumns=');
$alterOperations = strpos($source, "ALTER TABLE erp_operations ADD COLUMN", $operationColumns ?: 0);
$prepareOperationType = strpos($source, "prepare('INSERT OR IGNORE INTO erp_operation_types", $operationColumns ?: 0);

if ($operationColumns === false || $alterOperations === false || $prepareOperationType === false) {
    fwrite(STDERR, "Could not find the operation-type migration statements.\n");
    exit(1);
}

if ($prepareOperationType < $alterOperations) {
    fwrite(STDERR, "The operation-type insert is prepared before the operation schema changes.\n");
    exit(1);
}

fwrite(STDOUT, "Operation-type inserts are prepared after operation schema changes.\n");
