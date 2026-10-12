<?php
require_once __DIR__.'/../app/Services/SalesOrderConfirmation.php';
function confirmation_check($condition,$label){if(!$condition)throw new RuntimeException($label);echo "PASS: $label\n";}
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$db->exec('PRAGMA foreign_keys=ON');
foreach([
'users'=>'id INTEGER PRIMARY KEY,name TEXT',
'erp_customers'=>'id INTEGER PRIMARY KEY,code TEXT,name TEXT,is_active INTEGER,tax_number TEXT,address TEXT,address_2 TEXT,postal_code TEXT,city TEXT,country TEXT',
'erp_units'=>'id INTEGER PRIMARY KEY,code TEXT',
'erp_finished_products'=>'id INTEGER PRIMARY KEY,code TEXT,description TEXT,customer_id INTEGER,unit_id INTEGER,status TEXT,composition TEXT,material TEXT,bag_color TEXT,grammage TEXT,colors_per_face TEXT,has_gusset INTEGER,microperforation INTEGER',
'erp_number_sequences'=>'id INTEGER PRIMARY KEY,code TEXT UNIQUE,prefix TEXT,next_number INTEGER,padding INTEGER,suffix TEXT,updated_at TEXT',
'erp_production_orders'=>'id INTEGER PRIMARY KEY',
'erp_stock_movements'=>'id INTEGER PRIMARY KEY,movement_number TEXT,movement_date TEXT,quantity REAL,reversal_of_id INTEGER',
'erp_product_documents'=>'id INTEGER PRIMARY KEY,entity_type TEXT,entity_id INTEGER,document_type TEXT,title TEXT,file_url TEXT,version TEXT,author_user_id INTEGER REFERENCES users(id),status TEXT,notes TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP',
'erp_audit_log'=>'id INTEGER PRIMARY KEY,user_id INTEGER,action TEXT,entity TEXT,entity_id INTEGER,old_values_json TEXT,new_values_json,reason TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP'
] as $table=>$columns)$db->exec('CREATE TABLE '.$table.'('.$columns.')');
$db->exec("INSERT INTO users VALUES(1,'Responsável');INSERT INTO erp_customers VALUES(1,'144','Cliente (confirmação)',1,'506309681','Rua teste, 271','','4475-298','Maia','Portugal');INSERT INTO erp_units VALUES(1,'un');INSERT INTO erp_finished_products VALUES(1,'A1','Saco ráfia',1,1,'Ativo','Ráfia laminada','Ráfia','Branca','60+20','2',1,0)");
SalesOrderSchema::migrate($db);$orders=new SalesOrderService($db);$db->beginTransaction();$id=$orders->save(['customer_id'=>'1','order_date'=>'2026-10-12','status'=>'Confirmada','requisition_number'=>'REQ-007','lines'=>[['finished_product_id'=>'1','quantity'=>'15000','unit_price'=>'.320']]],1,true);$db->commit();
$root=sys_get_temp_dir().'/confirmation-test-'.bin2hex(random_bytes(5));$company=['name'=>'Tisser, Lda','address'=>'Zona Industrial do Cerrado, Rua 13, 416','phone'=>'+351 220813858','fax'=>'+351 220813858','email'=>'tisser@example.invalid','tax_number'=>'508757509','terms'=>SalesOrderConfirmation::DEFAULT_TERMS,'logo_data'=>''];$service=new SalesOrderConfirmation($db,$root);
try{
    $db->beginTransaction();$first=$service->issue($id,1,1,$company,'Responsável');$db->commit();
    confirmation_check($first['number']==='CONF-00001'&&strpos($first['content'],'<!doctype html>')!==false,'First issued confirmation has independent sequential number and printable A4 document');
    confirmation_check(file_get_contents($first['path'])===$first['content'],'Archived file exactly matches issued bytes');
    $metadata=json_decode($db->query('SELECT notes FROM erp_product_documents WHERE id='.$first['id'])->fetchColumn(),true);
    confirmation_check($metadata['customer']['tax_number']==='506309681'&&(float)$metadata['lines'][0]['unit_price']===.320&&(float)$metadata['lines'][0]['line_total']===4800.0,'Snapshot includes customer NIF and recorded commercial amounts');
    confirmation_check(strpos($first['content'],'Nr. Requisição: REQ-007')!==false&&strpos($first['content'],'Processado por')===false,'Requisition printed and issuer removed from footer');
    confirmation_check(strpos($first['content'],'Portugal</p><p>NIF: 506309681')!==false&&strpos($first['content'],'3%')!==false&&strpos($first['content'],'10 mm')!==false,'Customer NIF after country and restored tolerances in footer');
    $db->exec("UPDATE erp_customers SET name='Nome alterado' WHERE id=1;UPDATE erp_finished_products SET description='Artigo alterado',composition='Composição nova' WHERE id=1");
    $db->beginTransaction();$repeat=$service->issue($id,99,1,$company,'Responsável');$db->commit();
    confirmation_check($repeat['number']===$first['number']&&$repeat['id']===$first['id']&&$repeat['content']===$first['content'],'Repeated click and stale page reuse original order confirmation without numbering');
    confirmation_check(hash_file('sha256',$first['path'])===$metadata['sha256']&&$metadata['customer']['name']==='Cliente (confirmação)','Re-reading archived document keeps original number, bytes and customer snapshot');
    $db->beginTransaction();$other=$orders->save(['customer_id'=>'1','order_date'=>'2026-10-12','status'=>'Confirmada','lines'=>[['finished_product_id'=>'1','quantity'=>'10','unit_price'=>'1']]],1,true);$second=$service->issue($other,1,1,$company,'Responsável');$db->commit();
    confirmation_check($second['number']==='CONF-00002'&&$second['id']!==$first['id'],'Different order receives its own next confirmation');
    $db->beginTransaction();$unissued=$orders->save(['customer_id'=>'1','order_date'=>'2026-10-12','status'=>'Confirmada','lines'=>[['finished_product_id'=>'1','quantity'=>'10']]],1,true);$db->commit();
    $next=NumberSequenceService::peek($db,'sales_order_confirmation');$db->beginTransaction();try{$service->issue($unissued,99,1,$company,'Responsável');throw new Exception('Stale issuance accepted');}catch(RuntimeException $e){$db->rollBack();}
    confirmation_check(NumberSequenceService::peek($db,'sales_order_confirmation')===$next,'Stale revision rejected without consuming a number');
    $db->beginTransaction();try{$service->issue($unissued,1,999,$company,'Responsável');throw new Exception('Invalid author accepted');}catch(PDOException $e){$db->rollBack();}
    confirmation_check(count(glob($root.'/sales-orders/*/confirmations/*.html'))===2&&NumberSequenceService::peek($db,'sales_order_confirmation')===$next,'Failed catalogue insert removes new file and rollback restores sequence');
    file_put_contents($root.'/blocked','not a directory');$blocked=new SalesOrderConfirmation($db,$root.'/blocked');$db->beginTransaction();try{$blocked->issue($unissued,1,1,$company,'Responsável');throw new Exception('Filesystem failure ignored');}catch(RuntimeException $e){$db->rollBack();}
    confirmation_check(NumberSequenceService::peek($db,'sales_order_confirmation')===$next&&(int)$db->query('SELECT COUNT(*) FROM erp_product_documents')->fetchColumn()===2,'Filesystem failure cannot register a document or consume its number');
    $lineId=(int)$db->query('SELECT id FROM erp_sales_order_lines WHERE sales_order_id='.$id)->fetchColumn();
    $db->beginTransaction();$orders->save(['id'=>(string)$id,'revision'=>'1','customer_id'=>'1','order_date'=>'2026-10-12','status'=>'Confirmada','requisition_number'=>'REQ-UPDATED','lines'=>[['id'=>(string)$lineId,'finished_product_id'=>'1','quantity'=>'15000','unit_price'=>'.320']]],1,true);$db->commit();
    $oldPath=$first['path'];$oldNotes=$db->query('SELECT notes FROM erp_product_documents WHERE id='.$first['id'])->fetchColumn();
    $db->beginTransaction();try{$service->issue($id,2,999,$company,'Responsável');throw new Exception('Invalid refresh author accepted');}catch(PDOException $e){$db->rollBack();}
    confirmation_check(file_get_contents($oldPath)===$first['content']&&$db->query('SELECT notes FROM erp_product_documents WHERE id='.$first['id'])->fetchColumn()===$oldNotes,'Failed refresh retains indexed document and earlier file');
    $db->beginTransaction();$refreshed=$service->issue($id,2,1,$company,'Responsável');$db->commit();
    confirmation_check($refreshed['id']===$first['id']&&$refreshed['number']===$first['number']&&strpos($refreshed['content'],'REQ-UPDATED')!==false&&NumberSequenceService::peek($db,'sales_order_confirmation')===$next,'Saved order changes refresh one indexed confirmation with same number');
    confirmation_check((int)$db->query('SELECT COUNT(*) FROM erp_product_documents')->fetchColumn()===2&&file_get_contents($oldPath)===$first['content'],'Refresh preserves earlier archived bytes without duplicate document records');
    $db->beginTransaction();$repeatRefresh=$service->issue($id,1,1,$company,'Responsável');$db->commit();
    confirmation_check($repeatRefresh['path']===$refreshed['path']&&!$repeatRefresh['created_file'],'Repeated refresh click simply reopens latest document');
    confirmation_check($db->query('PRAGMA foreign_key_check')->fetchAll()===[],'Issuance preserves foreign key integrity');
}finally{
    if(is_dir($root)){foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $item){$item->isDir()?rmdir($item->getPathname()):unlink($item->getPathname());}rmdir($root);}
}
