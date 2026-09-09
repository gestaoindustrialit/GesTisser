<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/Services/ArticleSpreadsheet.php';
$tmp=tempnam(sys_get_temp_dir(),'raw_material_csv_');file_put_contents($tmp,"codigo;descricao;categoria;unidade;stock_minimo;alertas_ativos\nMP-01;Polipropileno;raw_material;KG;100;Sim\n");
try{$rows=ArticleSpreadsheet::readWithColumns($tmp,'csv',['codigo'=>'code','descricao'=>'description','categoria'=>'product_category','unidade'=>'primary_unit','stock_minimo'=>'min_stock','alertas_ativos'=>'alert_enabled'],['codigo','descricao']);}finally{@unlink($tmp);}
if(count($rows)!==1||$rows[0]['codigo']!=='MP-01'||$rows[0]['stock_minimo']!=='100')throw new RuntimeException('A folha de matérias-primas não foi interpretada corretamente.');
echo "Importação de matérias-primas validada.\n";
