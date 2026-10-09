<?php
require_once __DIR__.'/../app/Services/MaterialProfile.php';
function material_check($condition,$message) { if (!$condition) throw new RuntimeException($message); }
if (empty($argv[1]) || !is_file($argv[1])) throw new RuntimeException('Indique a base SQLite para auditoria em leitura.');
$source=$argv[1]; $before=hash_file('sha256',$source);
$pdo=new PDO('sqlite:'.$source); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION); $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC); $pdo->exec('PRAGMA query_only=ON');
$service=new MaterialProfile($pdo);$filters=MaterialProfile::filters([]);
$sections=['stock','movements','rolls','inks','lots','bom','article_colors','routing','consumptions','reservations','roll_trace','suppliers','prices','documents'];
$ids=$pdo->query('SELECT id FROM erp_raw_materials')->fetchAll(PDO::FETCH_COLUMN);
foreach($ids as $id) {
    $material=$service->material((int)$id);material_check((int)$material['id']===(int)$id,'Material correto.');
    $summary=$service->summary((int)$id,$filters);
    $s=$pdo->prepare('SELECT SUM(physical_qty-reserved_qty-blocked_qty) FROM erp_stock_balances WHERE item_type="raw_material" AND item_id=?');$s->execute([$id]);$available=$s->fetchColumn();
    material_check($summary['stock']['available']==$available,'Disponibilidade preservada.');
    foreach($sections as $section){$data=$service->section((int)$id,$section,$filters,true);material_check(count($data['rows'])<=20,'Paginação limitada.');}
}
material_check($service->material(2147483647)===null,'Material inexistente.');
try{$service->section((int)$ids[0],'prices',$filters,false);throw new LogicException('Custos expostos.');}catch(RuntimeException $e){material_check(!($e instanceof LogicException),'Custos protegidos.');}
foreach([['from'=>'2026-02-30'],['from'=>'2026-10-10','to'=>'2026-10-01']] as $input) {
    try{MaterialProfile::filters($input);throw new LogicException('Filtro inválido aceite.');}catch(InvalidArgumentException $e){}
}
$literal=MaterialProfile::filters(['q'=>"%' OR 1=1 --",'p'=>999999]);
material_check($service->section((int)$ids[0],'movements',$literal)['total']===0,'Pesquisa tratada como dados.');

// Empty and mixed-unit scenarios exist only in an independent in-memory database.
$memory=new PDO('sqlite::memory:');$memory->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$memory->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
foreach($pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name LIKE 'erp_%'")->fetchAll(PDO::FETCH_COLUMN) as $sql)$memory->exec($sql);
$memory->exec("INSERT INTO erp_raw_materials(id,code,description,product_category) VALUES(1,'MEMORY_ONLY','<script>test</script>','subsidiary')");
$empty=new MaterialProfile($memory);$m=$empty->material(1);material_check($m['supplier_name']===null && $m['unit_code']===null,'Ausência de fornecedor e unidade preservada.');
$sum=$empty->summary(1,$filters);material_check($sum['stock']['physical']===null && $sum['last_entry']===null && !$sum['consumption'],'Ausência de dados distinta de zero.');
$memory->exec("INSERT INTO erp_stock_balances(item_type,item_id,warehouse_id,location_id,lot,physical_qty) VALUES('raw_material',1,0,0,'',0)");
material_check((float)$empty->summary(1,$filters)['stock']['physical']===0.0,'Zero físico real.');
$memory->exec("INSERT INTO erp_production_consumptions(production_order_id,product_id,raw_material_id,quantity,unit_code,completed_at,created_at) VALUES(1,1,1,2,'Kg','2026-10-09','2026-10-09'),(1,1,1,3,'L','2026-10-09','2026-10-09'),(1,1,1,99,'Kg',NULL,'2026-10-09')");
$consumed=$empty->summary(1,$filters)['consumption'];material_check(count($consumed)===2 && (float)$consumed[0]['quantity']===2.0 && (float)$consumed[1]['quantity']===3.0,'Unidades separadas e utilizações abertas excluídas.');
material_check(!$empty->summary(1,MaterialProfile::filters(['to'=>'2026-10-08']))['consumption'],'Filtro temporal do consumo.');
$memory->exec("INSERT INTO erp_finished_products(id,code,description) VALUES(1,'ARTICLE_MEMORY','Artigo de teste em memória')");
$memory->exec("INSERT INTO erp_article_materials(finished_product_id,raw_material_id,quantity_per_unit) VALUES(1,1,2)");
$memory->exec("INSERT INTO erp_operations(id,code,name) VALUES(1,'PRINT_MEMORY','Impressão')");
$memory->exec("INSERT INTO erp_article_routings(id,finished_product_id) VALUES(1,1)");
$memory->exec("INSERT INTO erp_article_routing_versions(id,routing_id,version_no) VALUES(1,1,1)");
$memory->exec("INSERT INTO erp_article_routing_steps(id,routing_version_id,operation_id,operation_no,sort_order,operation_snapshot_json) VALUES(1,1,1,1,1,'{}')");
$memory->exec("INSERT INTO erp_routing_step_materials(routing_step_id,material_id,quantity_per_unit) VALUES(1,1,3)");
$memory->exec("INSERT INTO erp_production_orders(id,order_number,product_id,planned_quantity) VALUES(1,'OF_MEMORY',1,10)");
$memory->exec("INSERT INTO erp_production_order_material_reservations(production_order_id,production_order_operation_id,material_id,material_category,required_qty,reserved_qty) VALUES(1,1,1,'subsidiary',30,5)");
$memory->exec("INSERT INTO erp_suppliers(id,code,name) VALUES(1,'SUP_MEMORY','Fornecedor em memória')");
$memory->exec("INSERT INTO erp_supplier_item_mappings(supplier_id,supplier_reference,item_type,item_id) VALUES(1,'MANUFACTURER_MEMORY','raw_material',1)");
$memory->exec("INSERT INTO erp_product_documents(entity_type,entity_id,document_type,title) VALUES('raw_material',1,'safety','Ficha de segurança em memória')");
material_check(MaterialProfile::hasInkCode('MEMORY_ONLY — designação antiga','MEMORY_ONLY')===1 && MaterialProfile::hasInkCode('MEMORY_ONLY_2 — outra','MEMORY_ONLY')===0,'Códigos de cores completos preservados.');
material_check($empty->section(1,'bom',$filters)['rows'][0]['article']==='ARTICLE_MEMORY','Relação real com artigo/BOM.');
material_check((float)$empty->section(1,'routing',$filters)['rows'][0]['quantity_per_unit']===3.0,'Routing separado da BOM.');
material_check((float)$empty->section(1,'reservations',$filters)['rows'][0]['reserved_qty']===5.0,'Reserva por OF.');
material_check($empty->section(1,'consumptions',$filters)['total']===3,'Consulta dos consumos e OF associados.');
material_check($empty->section(1,'suppliers',$filters)['rows'][0]['supplier_reference']==='MANUFACTURER_MEMORY','Referência do fornecedor preservada.');
material_check($empty->section(1,'documents',$filters)['total']===1,'Documentos associados ao material.');
material_check(hash_file('sha256',$source)===$before,'Base original intacta.');
echo 'PASS: '.count($ids).' materiais, 14 projeções por material, custos, filtros, ausência de dados, zero e unidades; SHA-256 original inalterado.'.PHP_EOL;
