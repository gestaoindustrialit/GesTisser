<?php
require_once __DIR__.'/../app/Services/CommercialNumbering.php';
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->exec('CREATE TABLE erp_number_sequences(id INTEGER PRIMARY KEY,code TEXT UNIQUE,prefix TEXT,next_number INTEGER,padding INTEGER,suffix TEXT,updated_at TEXT);CREATE TABLE erp_sales_orders(order_number TEXT);CREATE TABLE erp_product_documents(entity_type TEXT,document_type TEXT,version TEXT);INSERT INTO erp_sales_orders VALUES("EC-00012");INSERT INTO erp_product_documents VALUES("sales_order","Confirmação de encomenda","CONF-00007")');
foreach(['sales_order'=>[12,13,'EC-00013'],'sales_order_confirmation'=>[7,8,'CONF-00008']] as $code=>$values){
 $db->beginTransaction();try{CommercialNumbering::configure($db,$code,$values[0]);throw new LogicException('Used number accepted');}catch(InvalidArgumentException $e){$db->rollBack();}
 $db->beginTransaction();$result=CommercialNumbering::configure($db,$code,$values[1]);$db->commit();if($result['number']!==$values[2]||NumberSequenceService::peek($db,$code)!==$values[2])throw new RuntimeException('Counter update failed');
}
$db->beginTransaction();try{CommercialNumbering::configure($db,'work_order',1);throw new LogicException('Wrong sequence accepted');}catch(InvalidArgumentException $e){$db->rollBack();}
echo "PASS: customer order and confirmation counters accept unused next numbers, reject used numbers and other sequences.\n";
