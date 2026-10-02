<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/app/Services/ArticleSpreadsheet.php';
require_once dirname(__DIR__).'/app/Services/SimpleXlsx.php';

$pdo=new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE erp_customers (id INTEGER PRIMARY KEY,name TEXT)');
$pdo->exec('CREATE TABLE erp_finished_products (id INTEGER PRIMARY KEY,code TEXT,description TEXT,customer_id INTEGER,grammage TEXT,notes TEXT)');
$pdo->exec("INSERT INTO erp_customers(id,name) VALUES (1,'Cliente Excel')");
$pdo->exec("INSERT INTO erp_finished_products(id,code,description,customer_id,grammage,notes) VALUES (1,'ART-001','Artigo composto',1,'60+20','Não alterar maquete')");

$matrix=ArticleSpreadsheet::exportRows($pdo);
$grammageIndex=array_search('gramagem',$matrix[0],true);
if($grammageIndex===false || $matrix[1][$grammageIndex]!=='60+20'){
    throw new RuntimeException('A exportação não conservou a gramagem composta como texto.');
}

$path=SimpleXlsx::create($matrix,'Artigos');
try{
    $rows=ArticleSpreadsheet::read($path,'xlsx');
    if(count($rows)!==1 || $rows[0]['gramagem']!=='60+20' || $rows[0]['codigo']!=='ART-001'){
        throw new RuntimeException('O ciclo Excel exportar/importar alterou dados do artigo.');
    }
}finally{
    @unlink($path);
}

echo "Exportação e leitura de artigos preservam gramagens compostas: OK.\n";
