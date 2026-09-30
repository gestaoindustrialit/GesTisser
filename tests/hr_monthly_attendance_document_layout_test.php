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

$logoFixture = __DIR__ . '/.monthly-attendance-logo.png';
file_put_contents($logoFixture, 'company-logo');
try {
    $logoSource = taskforce_company_report_logo_src('tests/.monthly-attendance-logo.png');
    monthly_document_assert(strpos($logoSource, 'data:image/png;base64,') === 0, 'O logótipo local dos detalhes da empresa deve ser incorporado no documento.');
    monthly_document_assert(base64_decode(substr($logoSource, strlen('data:image/png;base64,')), true) === 'company-logo', 'O conteúdo incorporado do logótipo deve corresponder ao ficheiro configurado.');
} finally {
    @unlink($logoFixture);
}
monthly_document_assert(taskforce_company_report_logo_src('https://example.test/logo.svg') === 'https://example.test/logo.svg', 'Os URLs de logótipo devem ser preservados como na ficha técnica.');
monthly_document_assert(taskforce_company_report_logo_src('tests/logo-inexistente.png') === '', 'Um caminho de logótipo inexistente não deve ser apresentado.');

echo "Monthly attendance document layout tests passed.\n";
