<?php
require_once __DIR__.'/../app/Services/ColorimetryService.php';
require_once __DIR__.'/../app/Services/PreparedInkLabelRenderer.php';
function ink_check($value,$message){if(!$value)throw new RuntimeException($message);}
function ink_reject($fn,$message){$failed=false;try{$fn();}catch(Throwable $e){$failed=true;}ink_check($failed,$message);}
$dbPath=tempnam(sys_get_temp_dir(),'ink-concurrent-');$db=new PDO('sqlite:'.$dbPath);$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->exec('PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,name TEXT);CREATE TABLE erp_permissions(code TEXT PRIMARY KEY,label TEXT,description TEXT);CREATE TABLE erp_role_permissions(profile TEXT,permission_code TEXT);CREATE TABLE erp_user_permissions(user_id INTEGER,permission_code TEXT,is_allowed INTEGER,PRIMARY KEY(user_id,permission_code));CREATE TABLE erp_colors(id INTEGER PRIMARY KEY,code TEXT UNIQUE,name TEXT,pantone TEXT);CREATE TABLE erp_customers(id INTEGER PRIMARY KEY);CREATE TABLE erp_finished_products(id INTEGER PRIMARY KEY);CREATE TABLE erp_ink_types(id INTEGER PRIMARY KEY);CREATE TABLE erp_units(id INTEGER PRIMARY KEY,code TEXT);CREATE TABLE erp_raw_materials(id INTEGER PRIMARY KEY,code TEXT,description TEXT,status TEXT,primary_unit_id INTEGER,average_price REAL);CREATE TABLE erp_warehouses(id INTEGER PRIMARY KEY);CREATE TABLE erp_production_orders(id INTEGER PRIMARY KEY,product_id INTEGER);CREATE TABLE erp_stock_balances(item_type TEXT,item_id INTEGER,warehouse_id INTEGER,location_id INTEGER,lot TEXT,physical_qty REAL DEFAULT 0,reserved_qty REAL DEFAULT 0,blocked_qty REAL DEFAULT 0,updated_at TEXT,PRIMARY KEY(item_type,item_id,warehouse_id,location_id,lot));CREATE TABLE erp_stock_movements(id INTEGER PRIMARY KEY,movement_number TEXT,movement_type TEXT,item_type TEXT,item_id INTEGER,lot TEXT,quantity REAL,weight REAL,warehouse_from_id INTEGER,location_from_id INTEGER,unit_cost REAL,total_cost REAL,source_type TEXT,reason TEXT,notes TEXT,created_by INTEGER);CREATE TABLE erp_production_consumptions(id INTEGER PRIMARY KEY,production_order_id INTEGER,product_id INTEGER,quantity REAL,created_by INTEGER,consumption_type TEXT,stock_unit_type TEXT,stock_unit_id INTEGER,quantity_before REAL,quantity_after REAL,unit_code TEXT,completed_at TEXT);INSERT INTO users VALUES(1,"Admin"),(2,"Colorista"),(3,"Sem acesso");INSERT INTO erp_units VALUES(1,"kg"),(2,"L");INSERT INTO erp_raw_materials VALUES(1,"AZ","Azul","Ativo",1,5),(2,"BR","Branco","Ativo",1,2),(3,"PR","Preto","Ativo",1,4),(4,"VOL","Volume","Ativo",2,1);INSERT INTO erp_warehouses VALUES(1);INSERT INTO erp_production_orders VALUES(1,100);INSERT INTO erp_stock_balances(item_type,item_id,warehouse_id,location_id,lot,physical_qty) VALUES("raw_material",1,1,0,"A",100),("raw_material",2,1,0,"A",100),("raw_material",3,1,0,"A",100);');
ColorimetryService::migrate($db);ColorimetryService::migrate($db);
$admin=new ColorimetryService($db,['id'=>1,'is_admin'=>1]);$operator=new ColorimetryService($db,['id'=>2]);$denied=new ColorimetryService($db,['id'=>3]);
ink_reject(function()use($denied){$denied->authorize();},'Acesso sem permissão bloqueado');
$db->exec('INSERT INTO erp_user_permissions VALUES(2,"ink.prepare",1)');ink_check($operator->can(),'Permissão adicional sem perfil');
function ink_run($service,$action,$data){return $service->execute($action,$data,bin2hex(random_bytes(16)));}
ink_reject(function()use($operator){ink_run($operator,'formula',['color_id'=>1]);},'Colorista não cria fórmulas');
ink_check(ColorimetryService::mass('1,234567','kg')===1234567,'Conversão decimal exata');
ink_reject(function(){ColorimetryService::mass('1','L');},'Sem conversão de volume');ink_reject(function(){ColorimetryService::mass('0.0001','g');},'Rejeitar perda silenciosa de precisão');
$color=ink_run($admin,'color',['code'=>'T-001','name'=>'Azul de teste','pantone'=>'Referência fornecida','customer_id'=>'','article_id'=>'','ink_type_id'=>'']);$cid=$color['color_id'];
$components=[['material_id'=>1,'quantity'=>'650','unit'=>'g','source_balance_id'=>1],['material_id'=>2,'quantity'=>'250','unit'=>'g','source_balance_id'=>2],['material_id'=>3,'quantity'=>'100','unit'=>'g','source_balance_id'=>3]];
$f=ink_run($admin,'formula',['color_id'=>$cid,'components'=>$components]);$fid=$f['formula_id'];
ink_reject(function()use($operator,$fid){ink_run($operator,'formula_state',['id'=>$fid,'status'=>'Aprovada']);},'Aprovação separada');
$reference=['formula_id'=>$fid,'name'=>'referencia.png','content'=>base64_encode(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='))];
ink_run($admin,'attach',$reference);ink_check((int)$db->query('SELECT COUNT(*) FROM erp_ink_formula_attachments')->fetchColumn()===1,'Referência validada pelo UploadService');
ink_run($admin,'formula_state',['id'=>$fid,'status'=>'Aprovada']);
ink_reject(function()use($admin,$reference){ink_run($admin,'attach',$reference);},'Anexos aprovados não são alterados retroativamente');
ink_reject(function()use($admin,$cid,$fid,$components){ink_run($admin,'formula',['id'=>$fid,'color_id'=>$cid,'components'=>$components]);},'Fórmula aprovada imutável');
$scaled=ColorimetryService::scale(json_decode($admin->formula($fid)['components_json'],true),5000000);ink_check(array_column($scaled,'mg')===[3250000,1250000,500000],'Cálculo proporcional 5 kg');
$thirds=ColorimetryService::scale([['mg'=>1],['mg'=>1],['mg'=>1]],1000000);ink_check(array_sum(array_column($thirds,'mg'))===1000000,'Arredondamento conserva massa');
$actual=[['material_id'=>1,'quantity'=>'3300','source_balance_id'=>1],['material_id'=>2,'quantity'=>'1200','source_balance_id'=>2],['material_id'=>3,'quantity'=>'500','source_balance_id'=>3]];
$prepare=['formula_id'=>$fid,'quantity'=>'5','unit'=>'kg','components'=>$actual,'containers'=>[['quantity'=>'2500','lot'=>'L1','warehouse_id'=>1],['quantity'=>'2500','lot'=>'L1','warehouse_id'=>1]],'notes'=>'Pesagens reais diferentes'];
$key=bin2hex(random_bytes(16));$mix=$operator->execute('prepare',$prepare,$key);$repeat=$operator->execute('prepare',$prepare,$key);ink_check($mix===$repeat,'Dupla submissão devolve o mesmo resultado');
ink_check((int)$db->query('SELECT COUNT(*) FROM erp_ink_mixes')->fetchColumn()===1,'Sem mistura duplicada');ink_check((int)$db->query('SELECT COUNT(*) FROM erp_stock_movements')->fetchColumn()===3,'Consumo de componentes uma só vez');
ink_check(abs((float)$db->query('SELECT physical_qty FROM erp_stock_balances WHERE rowid=1')->fetchColumn()-96.7)<.000001,'Baixa das pesagens reais no inventário');
$b1=$mix['containers'][0];$b2=$mix['containers'][1];ink_check($b1!==$b2,'Recipientes exclusivos');
$before=$db->query('SELECT snapshot_json FROM erp_ink_mixes')->fetchColumn();$snapshot=json_decode($before,true);ink_check($snapshot['actual'][0]['mg']===3300000&&$snapshot['expected'][0]['mg']===3250000,'Previsto e real distintos');
ink_reject(function()use($operator,$b1){ink_run($operator,'consume',['container_id'=>$b1,'quantity'=>'1','notes'=>'Antes da avaliação']);},'Tinta não aprovada bloqueada');
ink_run($operator,'result',['container_id'=>$b1,'result'=>'Aprovado']);
$consumption=['container_id'=>$b1,'quantity'=>'1000','notes'=>'OF teste','production_order_id'=>1];$consumeKey=bin2hex(random_bytes(16));$consumed=$operator->execute('consume',$consumption,$consumeKey);$operator->execute('consume',$consumption,$consumeKey);
ink_check((int)$operator->container($b1)['available_mg']===1500000,'Consumo parcial idempotente');ink_check((int)$db->query('SELECT COUNT(*) FROM erp_production_consumptions')->fetchColumn()===1,'Sem consumo OF duplicado');
ink_reject(function()use($operator,$b1){ink_run($operator,'consume',['container_id'=>$b1,'quantity'=>'2000','notes'=>'Excesso']);},'Stock negativo bloqueado');
ink_run($operator,'correct',['container_id'=>$b1,'components'=>[['material_id'=>1,'quantity'=>'100','source_balance_id'=>1],['material_id'=>3,'quantity'=>'25','source_balance_id'=>3]],'notes'=>'Afinação após consumo']);
$b=$operator->container($b1);ink_check((int)$b['available_mg']===1625000,'Correção apenas do restante');$composition=json_decode($b['composition_json'],true);ink_check(array_sum(array_column($composition,'mg'))===1625000,'Composição final conserva massa');ink_check($composition[0]['mg']===1090000,'Componente remanescente proporcional mais correção');
ink_check($db->query('SELECT snapshot_json FROM erp_ink_mixes')->fetchColumn()===$before,'Histórico original imutável');
ink_run($operator,'result',['container_id'=>$b1,'result'=>'Aprovado','sample'=>'AM-1','measurements'=>'L*=42']);
ink_run($operator,'return',['container_id'=>$b1,'quantity'=>'500','notes'=>'Sobra OF','production_order_id'=>1,'consumption_event_id'=>$consumed['event_id']]);ink_check((int)$operator->container($b1)['available_mg']===2125000,'Devolução rastreada');
ink_reject(function()use($operator,$b1,$consumed){ink_run($operator,'return',['consumption_event_id'=>$consumed['event_id'],'container_id'=>$b1,'quantity'=>'600','notes'=>'Excesso','production_order_id'=>1]);},'Devolução não pode exceder consumo');
$new=ink_run($admin,'propose',['container_id'=>$b1,'notes'=>'Validar afinação']);$draft=$admin->formula($new['formula_id']);ink_check($draft['version']==2&&$draft['status']==='Em desenvolvimento','Afinação propõe nova versão sujeita a aprovação');
ink_check($admin->formula($fid)['status']==='Aprovada','Fórmula original preservada');
$stockBefore=$operator->container($b1)['available_mg'];ink_run($operator,'print',['container_id'=>$b1]);ink_run($operator,'print',['container_id'=>$b1]);ink_check($operator->container($b1)['available_mg']===$stockBefore,'Reimpressão sem stock novo');
$label=$operator->container($b1);$zpl=PreparedInkLabelRenderer::zpl($label,['width_mm'=>100,'height_mm'=>50],203);ink_check(strpos($zpl,'^BCN')!==false&&strpos($zpl,$label['code'])!==false,'Código único na Zebra');$pdf=PreparedInkLabelRenderer::pdf($label,['width_mm'=>100,'height_mm'=>50]);ink_check(substr($pdf,0,5)==='%PDF-','Etiqueta PDF válida');
ink_reject(function()use($db){$db->exec('DELETE FROM erp_ink_events');},'Histórico permanente');ink_reject(function()use($db){$db->exec('UPDATE erp_ink_mixes SET actual_mg=1');},'Snapshot imutável');
ink_check((int)$operator->container($b2)['available_mg']===2500000,'Recipiente irmão mantém saldo independente');
// Reuse source can identities and update their remaining weights with inventory.
$db->exec('CREATE TABLE erp_raw_material_ink_labels(id INTEGER PRIMARY KEY,raw_material_id INTEGER,warehouse_id INTEGER,supplier_lot TEXT,weight_kg REAL,status TEXT,updated_at TEXT)');
$sourceWeight=(float)$db->query('SELECT physical_qty FROM erp_stock_balances WHERE rowid=1')->fetchColumn();$db->prepare('INSERT INTO erp_raw_material_ink_labels VALUES(5,1,1,"A",?,"AVAILABLE",CURRENT_TIMESTAMP)')->execute([$sourceWeight]);
ink_reject(function()use($operator,$prepare){ink_run($operator,'prepare',$prepare);},'Lata de origem obrigatória quando existe etiqueta');
$withLabel=$prepare;$withLabel['components'][0]['source_label_id']=5;ink_run($operator,'prepare',$withLabel);
ink_check(abs((float)$db->query('SELECT weight_kg FROM erp_raw_material_ink_labels WHERE id=5')->fetchColumn()-($sourceWeight-3.3))<.000001,'Peso da lata acompanha a baixa do inventário');
$db->exec('UPDATE erp_raw_material_ink_labels SET status="IN_USE" WHERE id=5');
ink_reject(function()use($operator,$withLabel){ink_run($operator,'prepare',$withLabel);},'Lata em utilização não pode ser pesada duas vezes');
// Two independent PHP processes compete for the same 2.5 kg container.
ink_run($admin,'result',['container_id'=>$b2,'result'=>'Aprovado']);
$command=escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/support/colorimetry_worker.php').' '.escapeshellarg($dbPath).' '.(int)$b2;
$workers=[];for($i=0;$i<2;$i++){$pipes=[];$process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$workers[]=[$process,$pipes];}
$outcomes=[];foreach($workers as $worker){$out=stream_get_contents($worker[1][1]);$err=stream_get_contents($worker[1][2]);fclose($worker[1][1]);fclose($worker[1][2]);$exit=proc_close($worker[0]);ink_check($exit===0,'Worker PHP sem erros: '.$err);$outcomes[]=$out;}
ink_check(count(array_filter($outcomes,function($v){return $v==='insufficient';}))===1,'Um de dois consumos concorrentes rejeitado por stock');
ink_check((int)$admin->container($b2)['available_mg']===1000000,'Concorrência preserva saldo exato sem negativo');
$db->exec('UPDATE erp_user_permissions SET is_allowed=0 WHERE user_id=2');ink_reject(function()use($operator){$operator->authorize();},'Revogação de permissão efetiva no backend');
$db=null;unlink($dbPath);
echo "Colorimetria: permissões, precisão, versões, pesagens, recipientes, correções, consumos, devoluções, stock, idempotência, históricos e etiquetas validados.\n";
