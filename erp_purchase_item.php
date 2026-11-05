<?php
require_once __DIR__.'/helpers.php';
require_once __DIR__.'/erp_migrations.php';
require_once __DIR__.'/app/Services/InkMaterialSchema.php';
require_once __DIR__.'/app/Services/NumberSequenceService.php';
require_once __DIR__.'/app/Services/InkMaterialFields.php';
require_login();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$user=current_user($pdo)?:[];
function purchase_item_reply(int $status,array $payload){http_response_code($status);echo json_encode($payload,JSON_UNESCAPED_UNICODE);exit;}
if(!gt_erp_user_can($pdo,$user,'erp.view')||!gt_erp_user_can($pdo,$user,'erp.purchases')||!gt_erp_user_can($pdo,$user,'erp.master_data'))purchase_item_reply(403,['error'=>'Sem permissão para criar artigos.']);
if($_SERVER['REQUEST_METHOD']!=='POST')purchase_item_reply(405,['error'=>'Método não permitido.']);
$token=$_POST['_token']??'';
if(!is_string($token)||!hash_equals(csrf_token(),$token))purchase_item_reply(419,['error'=>'A sessão expirou. Mantenha esta encomenda aberta e autentique-se noutro separador.']);
try{
    gt_erp_run_phase1_migrations($pdo);
InkMaterialSchema::ensure($pdo);
    $type=(string)($_POST['item_type']??'raw_material');
    $category=(string)($_POST['product_category']??'raw_material');
    $code=trim((string)($_POST['code']??''));$description=trim((string)($_POST['description']??''));$unit=(int)($_POST['unit_id']??0);
    $priceText=str_replace(',','.',trim((string)($_POST['price']??'0')));$supplier=(int)($_POST['supplier_id']??0);
    if(!in_array($type,['raw_material','finished_product'],true)||!in_array($category,['raw_material','subsidiary','merchandise','packaging','other'],true))throw new RuntimeException('Selecione um tipo de artigo válido.');
    if($description===''||strlen($description)>1000||strlen($code)>100)throw new RuntimeException('Preencha uma descrição válida (até 1000 caracteres) e um código até 100 caracteres.');
    if(!is_numeric($priceText)||!is_finite((float)$priceText)||(float)$priceText<0)throw new RuntimeException('Indique um preço válido, igual ou superior a zero.');
    $q=$pdo->prepare('SELECT code FROM erp_units WHERE id=?');$q->execute([$unit]);$unitCode=$q->fetchColumn();if(!$unitCode)throw new RuntimeException('Selecione a unidade do artigo.');
    if($supplier){$q=$pdo->prepare('SELECT 1 FROM erp_suppliers WHERE id=? AND is_active=1');$q->execute([$supplier]);if(!$q->fetchColumn())throw new RuntimeException('Fornecedor inválido.');}
    $pdo->beginTransaction();$table=$type==='raw_material'?'erp_raw_materials':'erp_finished_products';
    if($code===''){
        $attempt=0;do{$code=NumberSequenceService::take($pdo,$type);$q=$pdo->prepare('SELECT 1 FROM '.$table.' WHERE code=? COLLATE NOCASE');$q->execute([$code]);$exists=$q->fetchColumn();}while($exists&&++$attempt<100);
        if($exists)throw new RuntimeException('Não foi possível atribuir um código automático. Indique um código.');
    }
    $q=$pdo->prepare('SELECT 1 FROM '.$table.' WHERE code=? COLLATE NOCASE');$q->execute([$code]);if($q->fetchColumn())throw new RuntimeException('Já existe um artigo com este código. Selecione-o na encomenda ou indique outro código.');
    $price=(float)$priceText;$uid=(int)$user['id'];$ink=[];if($type==='raw_material'){$input=$_POST;$input['code']=$code;$input['last_price']=$price;$ink=InkMaterialFields::normalize($pdo,$input);}
    if($type==='raw_material')$pdo->prepare('INSERT INTO erp_raw_materials(code,description,primary_unit_id,last_price,standard_price,preferred_supplier_id,product_category,roll_controlled,created_by,updated_by) VALUES(?,?,?,?,?,?,?,0,?,?)')->execute([$code,$description,$unit,$price,$price,$supplier?:null,$category,$uid,$uid]);
    else $pdo->prepare('INSERT INTO erp_finished_products(code,description,unit_id,standard_cost,created_by,updated_by) VALUES(?,?,?,?,?,?)')->execute([$code,$description,$unit,$price,$uid,$uid]);
    $id=(int)$pdo->lastInsertId();
    if($ink){$sets=[];foreach($ink as $field=>$value)$sets[]=$field.'=?';$params=array_values($ink);$params[]=$id;$pdo->prepare('UPDATE erp_raw_materials SET '.implode(',',$sets).' WHERE id=?')->execute($params);}
    gt_erp_audit($pdo,$uid,'create_from_purchase_order',$table,$id,[],array_merge(['code'=>$code,'description'=>$description,'unit_id'=>$unit,'price'=>$price],$ink));
    $pdo->commit();purchase_item_reply(201,['item'=>['id'=>$id,'item_type'=>$type,'code'=>$code,'description'=>$description,'unit_code'=>$unitCode,'price'=>$price,'is_ink'=>$ink['is_ink']??0,'ink_color'=>$ink['ink_color']??'','ink_pantone'=>$ink['ink_pantone']??'','legacy_ink_id'=>$ink['legacy_ink_id']??'','is_water_based_ink'=>$ink['is_water_based_ink']??0]]);
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();safe_log('Purchase quick item failed',['message'=>$e->getMessage()]);purchase_item_reply(422,['error'=>$e instanceof PDOException?'Não foi possível criar o artigo. Verifique o código e tente novamente.':$e->getMessage()]);}
