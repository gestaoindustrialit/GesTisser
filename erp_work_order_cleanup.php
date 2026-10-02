<?php
declare(strict_types=1);

/**
 * Permanently removes every manufacturing order and its production history.
 *
 * Most related records are removed by foreign-key cascades. The two tables
 * below intentionally use RESTRICT, so they must be cleared first.
 */
function gt_erp_delete_all_work_orders(PDO $pdo): int
{
    $count = (int) $pdo->query('SELECT COUNT(*) FROM erp_production_orders')->fetchColumn();

    $pdo->beginTransaction();
    try {
        foreach (['erp_raw_material_roll_consumptions', 'erp_production_order_closures'] as $table) {
            $exists = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");
            $exists->execute([$table]);
            if ($exists->fetchColumn()) {
                $pdo->exec('DELETE FROM ' . $table);
            }
        }

        $pdo->exec('DELETE FROM erp_production_orders');

        // The central audit table has no FK by design; remove only OF entries.
        $pdo->exec("DELETE FROM erp_audit_log WHERE entity IN ('erp_production_orders','erp_production_order_documents','erp_technical_sheets')");
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    return $count;
}
