<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/erp_migrations.php';
require_login();
erp_run_phase1_migrations($pdo);

$user = current_user($pdo) ?: [];
if (!erp_user_can($pdo, $user, 'erp.view')) {
    http_response_code(403);
    exit('Acesso reservado ao ERP.');
}

$columns = [
    'code' => 'Código', 'name' => 'Nome fiscal', 'tax_number' => 'NIF',
    'country' => 'País', 'country_prefix' => 'Prefixo país', 'phone' => 'Telefone',
    'mobile' => 'Telemóvel', 'email' => 'Email', 'address' => 'Morada 1',
    'address_2' => 'Morada 2', 'city' => 'Cidade', 'postal_code' => 'Código postal',
    'fax' => 'Fax', 'contact_name' => 'Contacto', 'salesperson' => 'Vendedor',
    'discount_percent' => 'Desconto %', 'balance' => 'Saldo', 'credit_limit' => 'Plafond',
    'notes' => 'Observações', 'is_active' => 'Estado',
];
$customers = $pdo->query('SELECT ' . implode(',', array_keys($columns)) . ' FROM erp_customers ORDER BY code COLLATE NOCASE, id')->fetchAll(PDO::FETCH_ASSOC);

function customer_export_cell($value, string $type = 'String'): string
{
    return '<Cell><Data ss:Type="' . $type . '">' . htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</Data></Cell>';
}

$rows = '<Row>';
foreach ($columns as $label) {
    $rows .= customer_export_cell($label);
}
$rows .= '</Row>';
$numericColumns = ['discount_percent', 'balance', 'credit_limit'];
foreach ($customers as $customer) {
    $rows .= '<Row>';
    foreach ($columns as $field => $label) {
        $value = $field === 'is_active' ? (!empty($customer[$field]) ? 'Ativo' : 'Inativo') : ($customer[$field] ?? '');
        $type = in_array($field, $numericColumns, true) && $value !== '' ? 'Number' : 'String';
        $rows .= customer_export_cell($value, $type);
    }
    $rows .= '</Row>';
}
$document = '<?xml version="1.0" encoding="UTF-8"?><?mso-application progid="Excel.Sheet"?>'
    . '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">'
    . '<Worksheet ss:Name="Clientes"><Table>' . $rows . '</Table></Worksheet></Workbook>';

header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="clientes_' . date('Y-m-d') . '.xls"');
header('Content-Length: ' . strlen($document));
echo $document;
