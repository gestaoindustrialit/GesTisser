<?php

$root = dirname(__DIR__);
$erp = file_get_contents($root . '/erp.php');
$javascript = file_get_contents($root . '/assets/app.js');

function expect_contains($contents, $needle, $message)
{
    if (strpos($contents, $needle) === false) {
        fwrite(STDERR, "FALHOU: {$message}\n");
        exit(1);
    }
}

foreach (['Clientes', 'Fornecedores', 'Artigos', 'Matérias-primas'] as $table) {
    expect_contains($erp, 'data-sortable-table="' . $table . '"', 'tabela ordenável em falta: ' . $table);
}

expect_contains($erp, "SELECT * FROM erp_customers ORDER BY id DESC'", 'os clientes devem ser todos carregados para a pesquisa');
expect_contains($erp, "SELECT * FROM erp_suppliers ORDER BY id DESC'", 'os fornecedores devem ser todos carregados para a pesquisa');
expect_contains($javascript, '<option value="15">15</option><option value="25">25</option><option value="50">50</option><option value="100">100</option><option value="all">Todos</option>', 'opções de tamanho da página incompletas');
expect_contains($javascript, 'class="sortable-table-close"', 'botão para fechar a pesquisa em falta');
expect_contains($javascript, 'const filteredRows = dataRows.filter', 'a paginação deve ser aplicada depois da pesquisa global');

echo "Tabelas ERP validadas: pesquisa, ordenação, fecho e paginação OK.\n";
