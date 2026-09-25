<?php
$printSource=(string)file_get_contents(__DIR__.'/../production_dossier_print.php');
$controllerSource=(string)file_get_contents(__DIR__.'/../production_dossier.php');
$pdfSource=(string)file_get_contents(__DIR__.'/../app/Services/ProductionDossierPdf.php');
function production_dossier_layout_check($condition,$message){if(!$condition)throw new RuntimeException($message);}
foreach(['Dados principais da encomenda','Identificação do cliente e do artigo','Maqueta do artigo / Referência visual','Registo de produção','Observações']as$title)production_dossier_layout_check(stripos($printSource,$title)!==false,'Secção em falta: '.$title);
production_dossier_layout_check(strpos($printSource,'@page{size:A4 portrait')!==false,'A impressão não está configurada para A4 vertical.');
production_dossier_layout_check(strpos($printSource,'object-fit:contain')!==false,'A maqueta não preserva a proporção.');
production_dossier_layout_check(strpos($printSource,'display:table-header-group')!==false,'O cabeçalho das operações não se repete.');
production_dossier_layout_check(strpos($printSource,'class="document-footer"')!==false,'Rodapé em falta.');
production_dossier_layout_check(strpos($controllerSource,'function pd_barcode128_html')!==false&&strpos($printSource,"pd_barcode128_html(\$o['order_number']")!==false,'Code 128 em falta.');
production_dossier_layout_check(strpos($controllerSource,"execute(['production_dossier'])")!==false,'Código documental não é dinâmico.');
production_dossier_layout_check(strpos($pdfSource,'barcode128')!==false,'Fallback sem Code 128.');
production_dossier_layout_check(strpos($controllerSource,"$"."productionOrderFrontColors=pd_order_colors($"."s['of_front_colors']??'')")!==false,'Cores da OF em falta.');
echo "production_dossier_print_layout_test: OK\n";
