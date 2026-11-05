<?php
require_once __DIR__.'/../app/Services/PurchaseOrderEmail.php';
function purchase_check($ok,$message){if(!$ok)throw new RuntimeException($message);}
function app_setting(PDO $pdo,$key,$fallback=''){return $fallback;}
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
foreach([
'erp_purchase_orders'=>'id INTEGER PRIMARY KEY,order_number TEXT,supplier_id INTEGER,order_date TEXT,expected_date TEXT,supplier_reference TEXT,notes TEXT,status TEXT',
'erp_suppliers'=>'id INTEGER PRIMARY KEY,name TEXT,address TEXT,address_2 TEXT,postal_code TEXT,country TEXT,email TEXT',
'erp_purchase_order_lines'=>'id INTEGER PRIMARY KEY,purchase_order_id INTEGER,line_number INTEGER,item_type TEXT,item_id INTEGER,description TEXT,quantity REAL,unit_price REAL,unit_normalized TEXT',
'erp_raw_materials'=>'id INTEGER PRIMARY KEY,code TEXT,description TEXT,primary_unit_id INTEGER,color_id INTEGER,ink_color TEXT,ink_pantone TEXT,legacy_ink_id TEXT,is_water_based_ink INTEGER',
'erp_finished_products'=>'id INTEGER PRIMARY KEY,code TEXT,description TEXT,unit_id INTEGER',
'erp_units'=>'id INTEGER PRIMARY KEY,code TEXT',
'erp_colors'=>'id INTEGER PRIMARY KEY,code TEXT,name TEXT,pantone TEXT',
'erp_stock_movements'=>'id INTEGER PRIMARY KEY,purchase_order_line_id INTEGER,movement_type TEXT,quantity REAL'
] as $table=>$columns)$pdo->exec('CREATE TABLE '.$table.'('.$columns.')');
$pdo->exec("INSERT INTO erp_purchase_orders VALUES(1,'ENC-1',1,'2026-11-05',NULL,'REF-1','Confirmar entrega','Aberta');INSERT INTO erp_suppliers VALUES(1,'SINTIGRAF','Rua teste','','','Portugal','compras@example.com');INSERT INTO erp_units VALUES(1,'KG');INSERT INTO erp_colors VALUES(1,'AM','AMARELO','130');INSERT INTO erp_raw_materials VALUES(1,'5SF08AM1012','Tinta amarela',1,1,NULL,NULL,NULL,0);INSERT INTO erp_finished_products VALUES(2,'PA-2','Artigo acabado',1);INSERT INTO erp_purchase_order_lines VALUES(10,1,1,'raw_material',1,'Descrição guardada',40,6.65,'KG');INSERT INTO erp_purchase_order_lines VALUES(11,1,2,'finished_product',2,'',20,6.49,'KG');INSERT INTO erp_stock_movements VALUES(1,10,'Entrada',20);");
$lines=PurchaseOrderDocument::lines($pdo,1);
purchase_check(count($lines)===2&&$lines[0]['item_description']==='Descrição guardada','Carregar várias linhas com descrição guardada.');
purchase_check($lines[0]['pantone']==='130'&&$lines[0]['color_name']==='AMARELO','Carregar cor e referência Pantone.');
purchase_check(PurchaseOrderDocument::progress($lines)===25.0,'Percentagem parcial por linha.');
$pdo->exec('UPDATE erp_raw_materials SET ink_color="AZUL",ink_pantone="072",legacy_ink_id="00009",is_water_based_ink=1 WHERE id=1');
$inkLine=PurchaseOrderDocument::lines($pdo,1)[0];purchase_check($inkLine['pantone']==='072'&&$inkLine['color_name']==='AZUL'&&$inkLine['legacy_ink_id']==='00009','Encomenda utiliza as referências próprias da tinta, em vez da cor genérica.');
$snapshot=PurchaseOrderDocument::snapshot($pdo,1);$pdf=PurchaseOrderDocument::pdf($snapshot);
purchase_check(substr($pdf,0,5)==='%PDF-','PDF gerado no runtime PHP 7.0.');
$long=$snapshot;$long['lines']=array_fill(0,120,$snapshot['lines'][0]);$longPdf=PurchaseOrderDocument::pdf($long);
purchase_check(preg_match_all('#/Type\s*/Page\b#',$longPdf,$matches)>1,'PDF com múltiplas páginas para encomendas extensas.');
$pdo->exec('UPDATE erp_raw_materials SET description="Alteração posterior";INSERT INTO erp_stock_movements VALUES(2,10,"Entrada",20);INSERT INTO erp_stock_movements VALUES(3,11,"Entrada",20);');
purchase_check(PurchaseOrderDocument::progress(PurchaseOrderDocument::lines($pdo,1))===100.0,'Conclusão de todas as linhas.');
purchase_check($snapshot['lines'][0]['item_description']==='Descrição guardada','Snapshot mantém rastreabilidade.');
$pdo->exec('CREATE TABLE erp_purchase_order_emails(id INTEGER PRIMARY KEY,purchase_order_id INTEGER,recipient TEXT,subject TEXT,body TEXT,snapshot_json TEXT,pdf_content BLOB,pdf_sha256 TEXT,dispatch_key TEXT UNIQUE,status TEXT,error_message TEXT,created_by INTEGER)');
$data=['purchase_order_id'=>1,'recipient'=>'compras@example.com','subject'=>'Encomenda ENC-1','body'=>'Texto definido','dispatch_key'=>str_repeat('a',32)];
$calls=0;$transport=function($to,$subject,$body,$attachments)use($pdo,&$calls){
    $calls++;purchase_check(!$pdo->inTransaction(),'SMTP fora da transação.');
    purchase_check($pdo->query('SELECT status FROM erp_purchase_order_emails ORDER BY id DESC LIMIT 1')->fetchColumn()==='Em envio','Tentativa arquivada antes do transporte.');
    purchase_check($to==='compras@example.com'&&$body==='Texto definido'&&substr($attachments[0]['content'],0,5)==='%PDF-','Transporta mensagem e PDF.');return ['sent'=>true];
};
$emailId=PurchaseOrderEmail::send($pdo,$data,7,$transport);
purchase_check(PurchaseOrderEmail::send($pdo,$data,7,$transport)===$emailId&&$calls===1,'Submissão repetida não volta a enviar.');
$archived=$pdo->query('SELECT * FROM erp_purchase_order_emails')->fetch(PDO::FETCH_ASSOC);
purchase_check($archived['status']==='Enviado'&&hash('sha256',$archived['pdf_content'])===$archived['pdf_sha256'],'Arquivo binário íntegro e estado de envio.');
$data['dispatch_key']=str_repeat('b',32);PurchaseOrderEmail::send($pdo,$data,7,function(){throw new RuntimeException('Falha SMTP de teste');});
purchase_check($pdo->query('SELECT status FROM erp_purchase_order_emails ORDER BY id DESC LIMIT 1')->fetchColumn()==='Falhou','Falha do transporte fica arquivada.');
$pdo->exec('UPDATE erp_purchase_orders SET status="Cancelada"');$data['dispatch_key']=str_repeat('c',32);
try{PurchaseOrderEmail::send($pdo,$data,7,$transport);throw new RuntimeException('Envio cancelado não foi bloqueado.');}catch(RuntimeException $e){purchase_check(strpos($e->getMessage(),'Abra e guarde')!==false,'Encomenda cancelada rejeitada.');}
purchase_check((int)$pdo->query('SELECT COUNT(*) FROM erp_purchase_order_emails')->fetchColumn()===2,'Cancelamento não criou envios.');
echo "purchase_order_document_test: OK\n";
