<?php
/** Shared customer form writer. Call only inside an authenticated, CSRF-checked transaction. */
final class CustomerEditor
{
    private static function text($value)
    {
        if (!is_scalar($value) && $value !== null) {
            throw new RuntimeException('Os campos do cliente devem conter valores simples.');
        }
        return trim((string) $value);
    }

    public static function save(PDO $pdo, array $input, $userId)
    {
        if (!$pdo->inTransaction()) {
            throw new LogicException('CustomerEditor requires a transaction.');
        }
        require_once __DIR__ . '/CustomerSpreadsheet.php';
        $id = (int) self::text($input['customer_id'] ?? 0);
        $fields = array_values(array_unique(CustomerSpreadsheet::columns()));
        $values = [];
        foreach ($fields as $field) {
            $raw = self::text($input[$field] ?? '');
            $values[$field] = in_array($field, ['discount_percent', 'balance', 'credit_limit'], true)
                ? (float) str_replace(',', '.', $raw)
                : ($field === 'is_active' ? (int) $raw : $raw);
        }
        if ($values['code'] === '' || $values['name'] === '') {
            throw new RuntimeException('O código e o nome são obrigatórios.');
        }
        $existing = [];
        if ($id > 0) {
            $find = $pdo->prepare('SELECT * FROM erp_customers WHERE id=?');
            $find->execute([$id]);
            $before = $find->fetch(PDO::FETCH_ASSOC);
            if (!$before) { throw new RuntimeException('Cliente não encontrado.'); }
            $find = $pdo->prepare('SELECT * FROM erp_customer_delivery_addresses WHERE customer_id=? ORDER BY id');
            $find->execute([$id]);
            foreach ($find->fetchAll(PDO::FETCH_ASSOC) as $address) { $existing[(int) $address['id']] = $address; }
        } else { $before = []; }
        $addresses = [];
        $used = [];
        $columns = ['id', 'label', 'address', 'postal_code', 'city', 'country', 'transporter'];
        foreach ($columns as $column) {
            $key = 'delivery_' . $column;
            if (isset($input[$key]) && !is_array($input[$key])) {
                throw new RuntimeException('Formato inválido das moradas de entrega.');
            }
        }
        foreach (($input['delivery_address'] ?? []) as $index => $unused) {
            $row = [];
            foreach ($columns as $column) { $row[$column] = self::text($input['delivery_' . $column][$index] ?? ''); }
            $addressId = $row['id'] === '' ? 0 : filter_var($row['id'], FILTER_VALIDATE_INT);
            if ($addressId === false || $addressId < 0) { throw new RuntimeException('Identificador de morada inválido.'); }
            if ($row['address'] === '' && $row['label'] === '' && $row['transporter'] === '') { continue; }
            if ($row['address'] === '' || $row['transporter'] === '') {
                throw new RuntimeException('Cada morada de entrega deve indicar a morada e a empresa transportadora.');
            }
            if ($addressId > 0 && (!isset($existing[$addressId]) || isset($used[$addressId]))) {
                throw new RuntimeException('Morada de entrega inválida para este cliente.');
            }
            if ($addressId > 0) { $used[$addressId] = true; }
            $row['id'] = $addressId;
            $row['label'] = $row['label'] ?: 'Morada ' . (count($addresses) + 1);
            $row['country'] = $row['country'] ?: 'Portugal';
            $addresses[] = $row;
        }
        // Removing a destination must never silently detach an existing OF.
        $referenced = $pdo->prepare('SELECT 1 FROM erp_production_orders WHERE delivery_address_id=? LIMIT 1');
        foreach ($existing as $addressId => $address) {
            if (isset($used[$addressId])) { continue; }
            $referenced->execute([$addressId]);
            if ($referenced->fetchColumn()) {
                throw new RuntimeException('Não é possível remover uma morada de entrega associada a uma OF.');
            }
        }
        if ($id > 0) {
            $sets = [];
            foreach ($fields as $field) { $sets[] = $field . '=?'; }
            $params = array_values($values); $params[] = $id;
            $pdo->prepare('UPDATE erp_customers SET ' . implode(',', $sets) . ',updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute($params);
            $verb = 'update';
        } else {
            $pdo->prepare('INSERT INTO erp_customers(' . implode(',', $fields) . ') VALUES (' . implode(',', array_fill(0, count($fields), '?')) . ')')->execute(array_values($values));
            $id = (int) $pdo->lastInsertId(); $verb = 'create';
        }
        $update = $pdo->prepare('UPDATE erp_customer_delivery_addresses SET label=?,address=?,postal_code=?,city=?,country=?,transporter=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND customer_id=?');
        $insert = $pdo->prepare('INSERT INTO erp_customer_delivery_addresses(label,address,postal_code,city,country,transporter,customer_id) VALUES (?,?,?,?,?,?,?)');
        foreach ($addresses as $row) {
            $params = [$row['label'], $row['address'], $row['postal_code'], $row['city'], $row['country'], $row['transporter']];
            if ($row['id'] > 0) { $params[] = $row['id']; $params[] = $id; $update->execute($params); }
            else { $params[] = $id; $insert->execute($params); }
        }
        $delete = $pdo->prepare('DELETE FROM erp_customer_delivery_addresses WHERE id=? AND customer_id=?');
        foreach ($existing as $addressId => $address) {
            if (!isset($used[$addressId])) { $delete->execute([$addressId, $id]); }
        }
        gt_erp_audit($pdo, $userId, $verb, 'erp_customers', $id, $before, $values);
        return ['id' => $id, 'created' => $verb === 'create'];
    }
}
