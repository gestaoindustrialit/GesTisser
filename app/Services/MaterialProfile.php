<?php
declare(strict_types=1);

/** Read-only projection of the existing material, WMS and production ledgers. */
final class MaterialProfile
{
    private $pdo;
    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        // Existing article selectors store one internal code / code-label per line.
        $pdo->sqliteCreateFunction('material_has_ink', [self::class,'hasInkCode'], 2);
    }
    public static function hasInkCode($value, $code): int
    {
        if ($code === null || (string)$code === '') return 0;
        $code = (string)$code;
        foreach (preg_split('/\R/u', (string)$value) as $line) {
            $line = trim($line);
            if ($line === $code || strpos($line, $code.' — ') === 0) return 1;
        }
        return 0;
    }

    public static function filters(array $input): array
    {
        $out = ['from'=>'', 'to'=>'', 'q'=>'', 'p'=>1];
        foreach (['from','to'] as $key) {
            $value = isset($input[$key]) && is_scalar($input[$key]) ? (string)$input[$key] : '';
            if ($value !== '') {
                $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
                if (!$date || $date->format('Y-m-d') !== $value) throw new InvalidArgumentException('Data inválida.');
            }
            $out[$key] = $value;
        }
        if ($out['from'] !== '' && $out['to'] !== '' && $out['from'] > $out['to']) throw new InvalidArgumentException('O início do período deve preceder o fim.');
        if (isset($input['q']) && is_scalar($input['q'])) $out['q'] = substr(trim((string)$input['q']), 0, 120);
        if (isset($input['p']) && is_scalar($input['p'])) $out['p'] = max(1, min(100000, (int)$input['p']));
        return $out;
    }

    private function rows(string $sql, array $params): array
    {
        $s = $this->pdo->prepare($sql); $s->execute($params); return $s->fetchAll(PDO::FETCH_ASSOC);
    }
    public function material(int $id)
    {
        $rows = $this->rows('SELECT r.*,u.code unit_code,s.name supplier_name,s.code supplier_code,t.name type_name,f.description feature_name,c.name color_name,c.code color_code,i.name ink_name FROM erp_raw_materials r LEFT JOIN erp_units u ON u.id=r.primary_unit_id LEFT JOIN erp_suppliers s ON s.id=r.preferred_supplier_id LEFT JOIN erp_material_types t ON t.id=r.material_type_id LEFT JOIN erp_material_features f ON f.id=r.material_feature_id LEFT JOIN erp_colors c ON c.id=r.color_id LEFT JOIN erp_ink_types i ON i.id=r.ink_type_id WHERE r.id=?', [$id]);
        return $rows ? $rows[0] : null;
    }
    private function period(string $field, array $filters, array &$params): string
    {
        $sql = '';
        if ($filters['from'] !== '') { $sql .= ' AND '.$field.'>=?'; $params[] = $filters['from']; }
        if ($filters['to'] !== '') { $sql .= ' AND '.$field.'<?'; $params[] = (new DateTimeImmutable($filters['to']))->modify('+1 day')->format('Y-m-d'); }
        return $sql;
    }
    public function summary(int $id, array $filters): array
    {
        $stock = $this->rows('SELECT COUNT(*) records,SUM(physical_qty) physical,SUM(reserved_qty) reserved,SUM(physical_qty-reserved_qty-blocked_qty) available FROM erp_stock_balances WHERE item_type="raw_material" AND item_id=?', [$id])[0];
        $last = $this->rows('SELECT MAX(movement_date) last_entry FROM erp_stock_movements WHERE item_type="raw_material" AND item_id=? AND movement_type="Entrada"', [$id])[0];
        $params = [$id]; $period = $this->period('created_at', $filters, $params);
        $consumption = $this->rows('SELECT unit_code,SUM(quantity) quantity,COUNT(*) records FROM erp_production_consumptions WHERE raw_material_id=? AND completed_at IS NOT NULL'.$period.' GROUP BY unit_code', $params);
        $lots = $this->rows('SELECT COUNT(*) total FROM (SELECT lot FROM erp_stock_balances WHERE item_type="raw_material" AND item_id=? AND TRIM(COALESCE(lot,""))<>"" UNION SELECT supplier_lot FROM erp_raw_material_roll_labels WHERE raw_material_id=? AND TRIM(supplier_lot)<>"" UNION SELECT supplier_lot FROM erp_raw_material_ink_labels WHERE raw_material_id=? AND TRIM(supplier_lot)<>"")', [$id,$id,$id])[0];
        return ['stock'=>$stock,'last_entry'=>$last['last_entry'],'consumption'=>$consumption,'lots'=>(int)$lots['total']];
    }

    /** Whitelisted projections; quantities of unrelated units are never combined. */
    public function section(int $id, string $section, array $filters, bool $costs = false): array
    {
        $params = [$id]; $date = ''; $search = '';
        switch ($section) {
            case 'stock':
                $sql = 'SELECT w.code warehouse,l.code location,b.lot,b.physical_qty,b.reserved_qty,b.blocked_qty,(b.physical_qty-b.reserved_qty-b.blocked_qty) available FROM erp_stock_balances b LEFT JOIN erp_warehouses w ON w.id=b.warehouse_id LEFT JOIN erp_locations l ON l.id=b.location_id WHERE b.item_type="raw_material" AND b.item_id=?';
                $search = 'COALESCE(b.lot,"")||" "||COALESCE(w.code,"")||" "||COALESCE(l.code,"")'; $order = 'w.code,l.code,b.lot'; break;
            case 'movements':
                $sql = 'SELECT movement_date,movement_number,movement_type,quantity,lot,source_type,source_id,order_reference FROM erp_stock_movements WHERE item_type="raw_material" AND item_id=?';
                $date = 'movement_date'; $search = 'movement_number||" "||movement_type||" "||COALESCE(lot,"")'; $order = 'movement_date DESC,id DESC'; break;
            case 'rolls':
            case 'inks':
                $table = $section === 'rolls' ? 'erp_raw_material_roll_labels' : 'erp_raw_material_ink_labels';
                $metres = $section === 'rolls' ? ',a.initial_metres,a.metres' : '';
                $sql = 'SELECT a.id,a.barcode,a.entry_number,a.supplier_lot,a.label_date,a.initial_weight_kg,a.weight_kg'.$metres.',a.status,w.code warehouse,l.code location FROM '.$table.' a LEFT JOIN erp_warehouses w ON w.id=a.warehouse_id LEFT JOIN erp_locations l ON l.id=a.location_id WHERE a.raw_material_id=?';
                $date = 'a.label_date'; $search = 'a.barcode||" "||a.supplier_lot||" "||a.entry_number'; $order = 'a.label_date DESC,a.id DESC'; break;
            case 'lots':
                // Physical balances are not receipt quantities or initial lot quantities.
                $sql = 'SELECT lot,SUM(physical_qty) physical,SUM(reserved_qty) reserved FROM erp_stock_balances WHERE item_type="raw_material" AND item_id=? AND TRIM(COALESCE(lot,""))<>""';
                $search = 'lot'; $order = 'lot'; break;
            case 'bom':
                $sql = 'SELECT p.id article_id,p.code article,p.description,a.quantity_per_unit,a.waste_percent,a.notes FROM erp_article_materials a JOIN erp_finished_products p ON p.id=a.finished_product_id WHERE a.raw_material_id=?';
                $search = 'p.code||" "||p.description'; $order = 'p.code'; break;
            case 'article_colors':
                $sql = 'SELECT p.id article_id,p.code article,p.description,material_has_ink(p.front_colors,r.code) technical_front,material_has_ink(p.back_colors,r.code) technical_back,material_has_ink(p.of_front_colors,r.code) order_front,material_has_ink(p.of_back_colors,r.code) order_back FROM erp_finished_products p JOIN erp_raw_materials r ON r.id=? WHERE (material_has_ink(p.front_colors,r.code)=1 OR material_has_ink(p.back_colors,r.code)=1 OR material_has_ink(p.of_front_colors,r.code)=1 OR material_has_ink(p.of_back_colors,r.code)=1)';
                $search = 'p.code||" "||p.description'; $order = 'p.code'; break;
            case 'routing':
                $sql = 'SELECT r.finished_product_id article_id,p.code article,v.id version_id,v.version_no,v.status,o.code operation,o.name operation_name,m.quantity_per_unit,m.reserve_on_order FROM erp_routing_step_materials m JOIN erp_article_routing_steps s ON s.id=m.routing_step_id JOIN erp_article_routing_versions v ON v.id=s.routing_version_id JOIN erp_article_routings r ON r.id=v.routing_id JOIN erp_finished_products p ON p.id=r.finished_product_id JOIN erp_operations o ON o.id=s.operation_id WHERE m.material_id=?';
                $search = 'p.code||" "||o.name'; $order = 'p.code,v.version_no,s.sort_order'; break;
            case 'consumptions':
                $sql = 'SELECT c.production_order_id,o.order_number,c.production_order_operation_id,c.created_at,c.planned_quantity,c.quantity,c.unit_code,c.lot,c.completed_at,c.source_movement_id FROM erp_production_consumptions c JOIN erp_production_orders o ON o.id=c.production_order_id WHERE c.raw_material_id=?';
                $date = 'c.created_at'; $search = 'o.order_number||" "||COALESCE(c.lot,"")'; $order = 'c.created_at DESC,c.id DESC'; break;
            case 'reservations':
                $sql = 'SELECT r.production_order_id,o.order_number,r.production_order_operation_id,r.required_qty,r.reserved_qty,r.lot,w.code warehouse,l.code location FROM erp_production_order_material_reservations r JOIN erp_production_orders o ON o.id=r.production_order_id LEFT JOIN erp_warehouses w ON w.id=r.warehouse_id LEFT JOIN erp_locations l ON l.id=r.location_id WHERE r.material_id=?';
                $search = 'o.order_number||" "||r.lot'; $order = 'o.order_number,r.id'; break;
            case 'roll_trace':
                $sql = 'SELECT c.production_order_id,o.order_number,a.barcode source_barcode,b.barcode resulting_barcode,c.consumed_metres,c.consumed_weight_kg,c.created_at FROM erp_raw_material_roll_consumptions c JOIN erp_raw_material_roll_labels a ON a.id=c.source_label_id JOIN erp_raw_material_roll_labels b ON b.id=c.resulting_label_id JOIN erp_production_orders o ON o.id=c.production_order_id WHERE a.raw_material_id=?';
                $date = 'c.created_at'; $search = 'o.order_number||" "||a.barcode'; $order = 'c.created_at DESC,c.id DESC'; break;
            case 'suppliers':
                $sql = 'SELECT s.id supplier_id,s.code supplier_code,s.name supplier_name,m.supplier_reference FROM erp_supplier_item_mappings m JOIN erp_suppliers s ON s.id=m.supplier_id WHERE m.item_type="raw_material" AND m.item_id=?';
                $search = 's.name||" "||m.supplier_reference'; $order = 's.name,m.supplier_reference'; break;
            case 'prices':
                if (!$costs) throw new RuntimeException('Sem permissão para consultar custos.');
                $sql = 'SELECT movement_date,movement_number,quantity,unit_cost,total_cost,source_type FROM erp_stock_movements WHERE item_type="raw_material" AND item_id=? AND movement_type="Entrada"';
                $date = 'movement_date'; $search = 'movement_number'; $order = 'movement_date DESC,id DESC'; break;
            case 'documents':
                $sql = 'SELECT title,document_type,version,valid_until,status,notes FROM erp_product_documents WHERE entity_type="raw_material" AND entity_id=?';
                $search = 'title||" "||document_type'; $order = 'created_at DESC,id DESC'; break;
            default: throw new InvalidArgumentException('Secção inválida.');
        }
        if ($date !== '') $sql .= $this->period($date, $filters, $params);
        if ($filters['q'] !== '') { $sql .= ' AND ('.$search.') LIKE ? ESCAPE "\\"'; $params[] = '%'.str_replace(['\\','%','_'], ['\\\\','\\%','\\_'], $filters['q']).'%'; }
        if ($section === 'lots') $sql .= ' GROUP BY lot';
        $total = (int)$this->rows('SELECT COUNT(*) total FROM ('.$sql.')', $params)[0]['total'];
        $page = min($filters['p'], max(1, (int)ceil($total / 20)));
        $rows = $this->rows($sql.' ORDER BY '.$order.' LIMIT 20 OFFSET '.(($page-1)*20), $params);
        return ['rows'=>$rows,'total'=>$total,'page'=>$page,'pages'=>max(1,(int)ceil($total/20))];
    }
}
