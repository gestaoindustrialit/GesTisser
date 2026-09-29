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
monthly_document_assert(substr_count($pdf, 'MAPA MENSAL DE PICAGENS') === 1, 'O título deve surgir uma única vez no cabeçalho.');
monthly_document_assert(strpos($pdf, 'Mapa mensal de picagens') === false, 'O corpo não deve repetir o título do cabeçalho.');
monthly_document_assert(strpos($pdf, 'Empresa Teste') !== false, 'O cabeçalho/rodapé deve identificar a empresa quando não existe logótipo.');
monthly_document_assert(strpos($pdf, 'Rua Industrial, 123') !== false, 'O cabeçalho deve apresentar a morada da empresa.');
monthly_document_assert(strpos($pdf, 'empresa@example.test') !== false, 'O cabeçalho deve apresentar os contactos da empresa.');

if (function_exists('imagecreatetruecolor') && function_exists('imagepng')) {
    $logoPath = sys_get_temp_dir() . '/monthly_attendance_logo_' . getmypid() . '.png';
    $logo = imagecreatetruecolor(120, 40);
    imagepng($logo, $logoPath);
    imagedestroy($logo);
    $pdfWithLogo = taskforce_generate_monthly_native_pdf([
        'company_name' => 'Empresa Teste', 'logo_path' => $logoPath, 'month' => 'Setembro de 2026',
    ]);
    monthly_document_assert(strpos($pdfWithLogo, '/Logo Do') !== false, 'O cabeçalho deve incorporar o logótipo configurado.');
    @unlink($logoPath);
}

echo "Monthly attendance document layout tests passed.\n";
