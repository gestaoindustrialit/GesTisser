<?php
$dossier=(string)file_get_contents(__DIR__.'/../production_dossier.php');$print=(string)file_get_contents(__DIR__.'/../production_dossier_print.php');
if(strpos($dossier,'function pd_barcode128_html')===false||strpos($print,"pd_barcode128_html(\$o['order_number']")===false)throw new RuntimeException('Código Code 128 da OF em falta.');
foreach(['customer_order','planned_quantity','customer_name','article_code','description','material','composition','width','length','grammage','thread_color','seam_type','due_date','printer_roll_measure','operations','notes']as$field)if(strpos($print,$field)===false)throw new RuntimeException('Campo dinâmico em falta: '.$field);
foreach(['Checklist Impressão','Quantidade boa','Desperdício','Eficiência','background:#075f4d','color:#075f4d']as$removed)if(strpos($print,$removed)!==false)throw new RuntimeException('Conteúdo removido ainda presente: '.$removed);
echo "production_order_print_fields_test: OK\n";
