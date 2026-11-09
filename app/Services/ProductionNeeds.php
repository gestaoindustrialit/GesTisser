<?php
/** Read-only forecast for the outstanding quantities of active orders. */
final class ProductionNeeds
{
    public static function load(PDO $pdo, array $orders): array
    {
        $rows = [];
        $snapshotQuery = $pdo->prepare('SELECT snapshot_json FROM erp_production_order_snapshots WHERE production_order_id=?');
        $legacyQuery = $pdo->prepare('SELECT snapshot_json FROM erp_technical_sheets WHERE production_order_id=?');
        $materialsQuery = $pdo->prepare('SELECT DISTINCT rm.id raw_material_id,rm.code,rm.description,mt.code material_type_code FROM erp_production_order_material_reservations r JOIN erp_raw_materials rm ON rm.id=r.material_id LEFT JOIN erp_material_types mt ON mt.id=rm.material_type_id WHERE r.production_order_id=?');
        $types = $pdo->query('SELECT rm.id,mt.code FROM erp_raw_materials rm LEFT JOIN erp_material_types mt ON mt.id=rm.material_type_id')->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($orders as $order) {
            $snapshotQuery->execute([$order['id']]);
            $json = $snapshotQuery->fetchColumn();
            if (!$json) { $legacyQuery->execute([$order['id']]); $json = $legacyQuery->fetchColumn(); }
            $snapshot = json_decode((string)$json, true);
            if (!is_array($snapshot)) $snapshot = [];
            $materialsQuery->execute([$order['id']]);
            $materials = $materialsQuery->fetchAll(PDO::FETCH_ASSOC);
            // The saved routing takes precedence over the article BOM.
            if (!$materials) $materials = (array)($snapshot['_materials'] ?? []);
            foreach ($materials as &$material) {
                $material['material_type_code'] = $material['material_type_code'] ?? ($types[$material['raw_material_id'] ?? 0] ?? '');
            }
            unset($material);
            $rows[] = ['order'=>$order, 'snapshot'=>$snapshot, 'materials'=>$materials];
        }
        return self::summarize($rows);
    }

    public static function summarize(array $rows): array
    {
        $result = ['planned'=>0.0, 'remaining'=>0.0, 'colors'=>[], 'raffia'=>[], 'missing'=>[]];
        foreach ($rows as $row) {
            $order = $row['order'];
            if (in_array($order['status'], ['Concluída','Fechada','Encerrada','Cancelada'], true)) continue;
            $planned = max(0, (float)$order['planned_quantity']);
            $remaining = max(0, $planned - max(0, (float)$order['produced_quantity']));
            $result['planned'] += $planned;
            $result['remaining'] += $remaining;
            if ($remaining <= 0) continue;
            $snapshot = $row['snapshot'];
            $colors = [];
            foreach (['front','back'] as $face) {
                $value = trim((string)($snapshot['of_'.$face.'_colors'] ?? ''));
                if ($value === '') $value = trim((string)($snapshot[$face.'_colors'] ?? ''));
                foreach (preg_split('/[\r\n;,]+/u', $value) as $color) if (trim($color) !== '') $colors[] = trim($color);
            }
            if (!$colors) foreach ((array)($snapshot['_colors'] ?? []) as $color) {
                $label = trim((string)($color['color'] ?? ''));
                $pantone = trim((string)($color['pantone'] ?? ''));
                if ($pantone !== '') $label .= ($label === '' ? '' : ' · ') . $pantone;
                if ($label !== '') $colors[] = $label;
            }
            foreach ($colors as $color) $result['colors'][mb_strtolower($color, 'UTF-8')] = $color;
            $length = (float)str_replace(',', '.', (string)($snapshot['length'] ?? 0));
            $seen = [];
            foreach ($row['materials'] as $material) {
                $type = mb_strtolower((string)($material['type'] ?? ''), 'UTF-8');
                if (($material['material_type_code'] ?? '') !== 'RF' && !in_array($type, ['ráfia','rafia','raffia'], true)) continue;
                $key = (string)($material['code'] ?? $material['raw_material_id']);
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                if ($length <= 0) continue;
                if (!isset($result['raffia'][$key])) $result['raffia'][$key] = ['code'=>$key, 'description'=>$material['description'] ?? '', 'metres'=>0.0];
                $result['raffia'][$key]['metres'] += $remaining * $length / 100 * 1.04;
            }
            if ($length <= 0 || !$seen) $result['missing'][] = $order['order_number'];
        }
        natcasesort($result['colors']);
        ksort($result['raffia'], SORT_NATURAL);
        return $result;
    }
}
