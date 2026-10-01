<?php
$source=(string)file_get_contents(__DIR__.'/../erp.php');
function production_history_check($condition,$message){if(!$condition)throw new RuntimeException($message);}
production_history_check(strpos($source,'Histórico de Produção')!==false,'O título do histórico de produção está em falta.');
foreach(['OF abertas','OF em produção','OF fechadas']as$filter)production_history_check(strpos($source,$filter)!==false,'Filtro em falta: '.$filter);
foreach(['Por iniciar','Em Produção','Em Pausa','Concluída','Encerrada']as$status)production_history_check(strpos($source,"'".$status."'")!==false,'Estado de OF em falta: '.$status);
production_history_check(strpos($source,'name="action" value="update_production_order_status"')!==false,'A ação para alterar o estado da OF está em falta.');
production_history_check(strpos($source,"'Por iniciar',trim(\$_POST['due_date']")!==false,'As novas OF devem começar no estado Por iniciar.');
echo "production_history_filter_test: OK\n";
