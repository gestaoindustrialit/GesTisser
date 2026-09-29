<?php
$erp=(string)file_get_contents(__DIR__.'/../erp.php');
$routing=(string)file_get_contents(__DIR__.'/../app/Services/RoutingService.php');
function creation_form_check($condition,$message){if(!$condition)throw new RuntimeException($message);}
$formStart=strpos($erp,'<input type="hidden" name="action" value="create_finished_order">');
$formEnd=strpos($erp,'</form>',$formStart);
$form=substr($erp,$formStart,$formEnd-$formStart);
foreach(['name="lot"','name="proof_number"','name="planned_pallets"','name="pallet_type"'] as$field)creation_form_check(strpos($form,$field)===false,'Campo removido ainda presente no formulário: '.$field);
creation_form_check(strpos($form,'name="routing_version_id"')!==false&&strpos($form,'data-of-routing-version')!==false,'Seletor da versão de routing em falta.');
creation_form_check(strpos($erp,'$automaticLot=\'T\'.date(\'Y\').str_pad')!==false,'Geração automática do lote em falta.');
creation_form_check(strpos($erp,'(int)($_POST[\'routing_version_id\']??0)')!==false,'A versão escolhida não é enviada ao snapshot.');
creation_form_check(strpos($routing,'v.id=? AND r.finished_product_id=?')!==false,'O routing escolhido não é validado contra o artigo.');
echo "production_order_creation_form_test: OK\n";
