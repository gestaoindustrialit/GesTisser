<?php
require_once __DIR__ . '/../app/Services/UserSpreadsheetExport.php';

$xml = UserSpreadsheetExport::build([[
    'id' => 7,
    'name' => 'Ana & Filhos',
    'email' => 'ana@example.test',
    'is_admin' => 1,
    'is_active' => 0,
    'department' => 'Corte e Cose',
    'schedule_name' => 'Produção 01',
    'tax_number' => '123456789',
    'password' => 'segredo',
    'pin_code_hash' => 'hash-secreto',
]]);

if (strpos($xml, 'Ana &amp; Filhos') === false) throw new RuntimeException('O export deve escapar valores XML.');
if (strpos($xml, 'N.º colaborador') === false || strpos($xml, 'NIF') === false) throw new RuntimeException('O export deve conter dados profissionais e pessoais.');
if (strpos($xml, 'Produção 01') === false) throw new RuntimeException('O export deve conter o nome do turno.');
if (strpos($xml, '>Sim<') === false || strpos($xml, '>Não<') === false) throw new RuntimeException('Os campos booleanos devem ser legíveis.');
if (strpos($xml, 'segredo') !== false || strpos($xml, 'hash-secreto') !== false) throw new RuntimeException('Credenciais nunca devem ser exportadas.');

echo "user_spreadsheet_export_test: OK\n";
