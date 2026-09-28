<?php
$dossier=(string)file_get_contents(__DIR__.'/../production_dossier.php');
$label=(string)file_get_contents(__DIR__.'/../production_label.php');
$roll=(string)file_get_contents(__DIR__.'/../raw_material_roll_label.php');
$ink=(string)file_get_contents(__DIR__.'/../raw_material_ink_label.php');
$shopfloor=(string)file_get_contents(__DIR__.'/../shopfloor.php');
$header=(string)file_get_contents(__DIR__.'/../partials/header.php');
$erp=(string)file_get_contents(__DIR__.'/../erp.php');
$migrations=(string)file_get_contents(__DIR__.'/../erp_migrations.php');
function production_label_check($condition,$message){if(!$condition)throw new RuntimeException($message);}
production_label_check(strpos($dossier,'production_label.php')===false,'o dossier ainda liga etiquetas a uma OF');
production_label_check(strpos($shopfloor,'production_label.php?id=')===false,'o Shopfloor ainda envia etiquetas com uma OF');
foreach(['roll','ink'] as$type){
 production_label_check(strpos($header,'data-bs-target="#productionLabelModal-'.$type.'"')!==false,'atalho de '.$type.' em falta no cabeçalho');
 production_label_check(strpos($shopfloor,'id="productionLabelModal-<?= h($labelType) ?>"')!==false,'seletor de matéria-prima em falta');
}
production_label_check(strpos($shopfloor,"'action'=>'raw_material_roll_label.php'")!==false&&strpos($shopfloor,"'action'=>'raw_material_ink_label.php'")!==false,'os botões não abrem as etiquetas de matéria-prima');
production_label_check(strpos($shopfloor,'name="raw_material_id"')!==false,'o popup não envia a matéria-prima');
production_label_check(strpos($label,"in_array(\$type,['roll','ink'],true)")!==false,'as rotas antigas não são redirecionadas');
production_label_check(strpos($erp,'raw_material_roll_label.php?raw_material_id=')!==false&&strpos($erp,'raw_material_ink_label.php?raw_material_id=')!==false,'atalhos das matérias-primas em falta');
foreach(['entry_number','supplier_lot','weight_kg','barcode','label_date','article_code','description'] as$field){production_label_check(strpos($roll,$field)!==false,'campo de rolo em falta: '.$field);production_label_check(strpos($ink,$field)!==false,'campo de tinta em falta: '.$field);}
production_label_check(strpos($roll,'metres')!==false,'metros do rolo em falta');
production_label_check(strpos($roll,'sem OF nem produto final')!==false&&strpos($ink,'sem OF nem produto final')!==false,'a independência das etiquetas não está explicada');
production_label_check(strpos($roll,'@page{size:100mm 50mm;margin:0}')!==false&&strpos($ink,'@page{size:100mm 50mm;margin:0}')!==false,'formato de impressão incorreto');
production_label_check(strpos($migrations,'erp_raw_material_roll_labels')!==false&&strpos($migrations,'erp_raw_material_ink_labels')!==false,'tabelas de etiquetas em falta');
echo "production_label_ui_test: OK\n";
