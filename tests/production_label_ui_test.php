<?php
$dossier=(string)file_get_contents(__DIR__.'/../production_dossier.php');
$label=(string)file_get_contents(__DIR__.'/../production_label.php');
$roll=(string)file_get_contents(__DIR__.'/../raw_material_roll_label.php');
$shopfloor=(string)file_get_contents(__DIR__.'/../shopfloor.php');
$header=(string)file_get_contents(__DIR__.'/../partials/header.php');
$erp=(string)file_get_contents(__DIR__.'/../erp.php');
$migrations=(string)file_get_contents(__DIR__.'/../erp_migrations.php');
function production_label_check($condition,$message){if(!$condition)throw new RuntimeException($message);}
production_label_check(strpos($dossier,'type=roll')===false,'o dossier ainda liga rolos a uma OF');
production_label_check(strpos($shopfloor,'type=roll')===false&&strpos($header,'productionLabelModal-roll')===false,'o Shopfloor ainda liga rolos a uma OF');
production_label_check(strpos($label,"if(\$type==='roll'){redirect('erp.php?page=raw_materials');}")!==false,'a rota antiga de rolos não é redirecionada');
production_label_check(strpos($erp,'raw_material_roll_label.php?raw_material_id=')!==false,'atalho da matéria-prima em falta');
foreach(['entry_number','supplier_lot','metres','weight_kg','barcode','label_date','article_code','description'] as$field)production_label_check(strpos($roll,$field)!==false,'campo de etiqueta em falta: '.$field);
production_label_check(strpos($roll,'sem OF nem produto final')!==false,'a independência da etiqueta não está explicada');
production_label_check(strpos($roll,'@page{size:100mm 50mm;margin:0}')!==false,'etiqueta não está configurada para 10 cm por 5 cm');
production_label_check(strpos($migrations,'erp_raw_material_roll_labels')!==false,'tabela de etiquetas de matéria-prima em falta');
echo "production_label_ui_test: OK\n";
