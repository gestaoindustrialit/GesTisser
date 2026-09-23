<?php
$dossier=(string)file_get_contents(__DIR__.'/../production_dossier.php');
$label=(string)file_get_contents(__DIR__.'/../production_label.php');
$shopfloor=(string)file_get_contents(__DIR__.'/../shopfloor.php');
$migrations=(string)file_get_contents(__DIR__.'/../erp_migrations.php');
function production_label_check($condition,$message){if(!$condition)throw new RuntimeException($message);}
production_label_check(strpos($dossier,'Etiqueta de rolo')!==false,'botão de etiqueta de rolo em falta');
production_label_check(strpos($dossier,'Etiqueta de tinta')!==false,'botão de etiqueta de tinta em falta');
production_label_check(strpos($label,'Atualizar e imprimir')!==false,'ação de atualização em falta');
production_label_check(strpos($label,'Imprimir últimos valores validados')!==false,'reimpressão dos valores validados em falta');
production_label_check(strpos($shopfloor,'aria-label="Etiquetas de acerto e reimpressão"')!==false,'ações de etiquetas no Shopfloor em falta');
production_label_check(strpos($shopfloor,'production_label.php?id=<?= (int)$selectedOf[\'id\'] ?>&type=roll')!==false,'etiqueta de rolo no Shopfloor em falta');
production_label_check(strpos($shopfloor,'production_label.php?id=<?= (int)$selectedOf[\'id\'] ?>&type=ink')!==false,'etiqueta de tinta no Shopfloor em falta');
production_label_check(strpos($label,"['Utilizador','Produção','Chefias','RH']")!==false,'workers do Shopfloor sem acesso às etiquetas');
production_label_check(strpos($migrations,'erp_production_label_history')!==false,'histórico de validações em falta');
echo "production_label_ui_test: OK\n";
