<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/Services/RawMaterialSpreadsheet.php';
$tmp=tempnam(sys_get_temp_dir(),'raw_material_csv_');file_put_contents($tmp,"codigo;descricao;categoria;unidade;stock_minimo;alertas_ativos\nMP-01;Polipropileno;raw_material;KG;100;Sim\n");
try{$rows=RawMaterialSpreadsheet::read($tmp,'csv');}finally{@unlink($tmp);}
if(count($rows)!==1||$rows[0]['codigo']!=='MP-01'||$rows[0]['stock_minimo']!=='100')throw new RuntimeException('A folha de matérias-primas não foi interpretada corretamente.');
if(!in_array('localizacao_standard',RawMaterialSpreadsheet::templateColumns(),true))throw new RuntimeException('O modelo não contém a localização standard.');
echo "Importação de matérias-primas validada.\n";
