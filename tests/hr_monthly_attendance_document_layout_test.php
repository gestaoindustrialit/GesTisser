<?php
require_once __DIR__ . '/../helpers.php';

function monthly_document_assert($condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdf = taskforce_generate_monthly_native_pdf([
    'company_name' => 'Empresa Teste',
    'document_number' => 'DOC-RH-TEST',
    'month' => 'Setembro de 2026',
    'period' => '01/09/2026 - 30/09/2026',
    'employee' => 'Colaborador Teste',
    'user_number' => '42',
    'department' => 'Recursos Humanos',
    'company_contacts' => ['Rua Industrial, 123', 'empresa@example.test'],
    'rows' => [],
    'summary' => [],
]);

monthly_document_assert(strncmp($pdf, '%PDF', 4) === 0, 'O mapa mensal deve produzir um PDF válido.');
monthly_document_assert(substr_count($pdf, 'DOC-RH-TEST') >= 1, 'O código documental deve constar no rodapé.');
monthly_document_assert(strpos($pdf, 'MAPA MENSAL DE PICAGENS') !== false, 'O cabeçalho normalizado deve identificar o documento.');
monthly_document_assert(strpos($pdf, 'Empresa Teste') !== false, 'O cabeçalho/rodapé deve identificar a empresa quando não existe logótipo.');
monthly_document_assert(strpos($pdf, 'Rua Industrial, 123') !== false, 'O cabeçalho deve apresentar a morada da empresa.');
monthly_document_assert(strpos($pdf, 'empresa@example.test') !== false, 'O cabeçalho deve apresentar os contactos da empresa.');

echo "Monthly attendance document layout tests passed.\n";
