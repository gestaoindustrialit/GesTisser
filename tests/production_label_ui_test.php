<?php
$dossier=(string)file_get_contents(__DIR__.'/../production_dossier.php');
$label=(string)file_get_contents(__DIR__.'/../production_label.php');
$migrations=(string)file_get_contents(__DIR__.'/../erp_migrations.php');
function production_label_check($condition,$message){if(!$condition)throw new RuntimeException($message);}
production_label_check(strpos($dossier,'Etiqueta de rolo')!==false,'botão de etiqueta de rolo em falta');
production_label_check(strpos($dossier,'Etiqueta de tinta')!==false,'botão de etiqueta de tinta em falta');
production_label_check(strpos($label,'Atualizar e imprimir')!==false,'ação de atualização em falta');
production_label_check(strpos($label,'Imprimir últimos valores validados')!==false,'reimpressão dos valores validados em falta');
production_label_check(strpos($migrations,'erp_production_label_history')!==false,'histórico de validações em falta');
echo "production_label_ui_test: OK\n";
