<?php
require __DIR__.'/../app/Services/SharePointArticleImport.php';
require __DIR__.'/../app/Services/SharePointIntegration.php';
function assert_import($ok,$label) { if (!$ok) throw new RuntimeException($label); }
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE erp_finished_products(id INTEGER PRIMARY KEY,code TEXT,description TEXT,status TEXT,updated_at TEXT,customer_id INTEGER);
CREATE TABLE erp_customers(id INTEGER PRIMARY KEY,code TEXT,name TEXT);
CREATE TABLE erp_product_documents(id INTEGER PRIMARY KEY,entity_type TEXT,entity_id INTEGER,status TEXT);
INSERT INTO erp_customers VALUES(1,"C001","Cliente de teste");
INSERT INTO erp_product_documents VALUES(1,"finished_product",1,"Ativo");');
for($i=1;$i<=6;$i++)$pdo->prepare('INSERT INTO erp_finished_products VALUES(?,?,?,?,?,?)')->execute([$i,'A'.$i,'Descrição '.$i,'Ativo','2026-10-26 08:00:00',1]);
$columns=[];foreach(['Title','Descri_x00e7__x00e3_o','CodigoCliente','Cliente','NumeroDocumentos','EstadoSincronizacao','GesTISSER_ID'] as $name) $columns[]=['name'=>$name,'text'=>['maxLength'=>255],'indexed'=>true,'enforceUniqueValues'=>true];
$columns[]=['name'=>'Estado','boolean'=>[]];$columns[]=['name'=>'DataAtualizacao','dateTime'=>[]];
$remote=[];$posts=[];$uncertain=false;
$api=function($method,$path,$body)use(&$columns,&$remote,&$posts,&$uncertain){
 if($method==='GET')return ['value'=>$path==='/columns'?$columns:$remote];
 $posts[]=$body;$item=['id'=>(string)(count($remote)+1),'fields'=>$body['fields']];$remote[]=$item;
 if($uncertain)throw new RuntimeException('sensitive transport content');
 return $item;
};
$service=new SharePointArticleImport($pdo,$api);
$preview=$service->preview(0);
assert_import(count($preview['rows'])===5 && !$posts,'preview bounded and read-only');
$f=$preview['rows'][0]['fields'];assert_import($f['Title']==='A1' && $f['GesTISSER_ID']==='1' && $f['Estado']===true && $f['NumeroDocumentos']==='1' && $f['CodigoCliente']==='C001','typed mapping');
assert_import(!isset($f['LinkTitle']) && !isset($f['ID']) && !isset($f['LinkGesTISSER']),'no read-only or unvalidated link writes');
$r=$service->execute($preview);assert_import(count($posts)===5 && $r[0]['result']==='Criado','create at most five');
$r=$service->execute($service->preview());assert_import(count($posts)===5 && strpos($r[0]['result'],'Já existe')===0,'repeat skips existing IDs');
try{$service->execute($preview);throw new LogicException('stale accepted');}catch(RuntimeException $e){assert_import(!($e instanceof LogicException),'stale preview rejected');}
$next=$service->preview(5);assert_import(count($next['rows'])===1 && $next['rows'][0]['id']===6,'next cursor');
$pdo->exec('UPDATE erp_finished_products SET description="changed" WHERE id=6');
try{$service->execute($next);throw new LogicException('local change accepted');}catch(RuntimeException $e){assert_import(!($e instanceof LogicException),'changed source rejected');}
$pdo->prepare('UPDATE erp_finished_products SET description=? WHERE id=6')->execute([str_repeat('á',256)]);
assert_import($service->preview(5)['rows'][0]['action']==='Bloqueado','long text blocked without truncation');
$pdo->exec('UPDATE erp_finished_products SET description="valid" WHERE id=6');
$remote[]=['id'=>'foreign','fields'=>['Title'=>'A6','GesTISSER_ID'=>'other']];assert_import($service->preview(5)['rows'][0]['action']==='Bloqueado','foreign code collision');array_pop($remote);
$uncertain=true;$r=$service->execute($service->preview(5));assert_import(strpos($r[0]['result'],'Não confirmado')===0,'uncertain creation stops');
$count=count($posts);$r=$service->execute($service->preview(5));assert_import(count($posts)===$count,'uncertain success reconciles without duplicate');
$columns[6]['enforceUniqueValues']=false;
try{$service->preview();throw new LogicException('nonunique key accepted');}catch(RuntimeException $e){assert_import(!($e instanceof LogicException),'unique schema required');}
// Exercise the actual scoped adapter with mocked HTTP envelopes.
$s=['tenant_id'=>'11111111-1111-1111-1111-111111111111','client_id'=>'22222222-2222-2222-2222-222222222222','site_id'=>'host,site,web','site_url'=>'https://tisserpt.sharepoint.com','list_id'=>'list','drive_id'=>'drive'];
$claims=['tid'=>$s['tenant_id'],'appid'=>$s['client_id'],'aud'=>'https://graph.microsoft.com','roles'=>['Sites.Selected'],'exp'=>time()+3600];
$jwt='e30.'.rtrim(strtr(base64_encode(json_encode($claims)),'+/','-_'),'=').'.sig';$writes=0;
$transport=function($url,$form,$bearer,$kind=null)use($s,$jwt,&$writes){
 if($kind==='POST_JSON'){++$writes;return ['status'=>201,'body'=>json_encode(['id'=>'1','fields'=>$form['fields']])];}
 if($form!==null)return ['status'=>200,'body'=>json_encode(['access_token'=>$jwt])];
 if(strpos($url,'/columns')!==false)$body=['value'=>[]];
 elseif(strpos($url,'/lists/')!==false)$body=['id'=>'list','webUrl'=>$s['site_url'].'/Lists/Artigos'];
 elseif(strpos($url,'/drives/')!==false)$body=['id'=>'drive','driveType'=>'documentLibrary','folder'=>[], 'parentReference'=>['driveId'=>'drive'],'sharepointIds'=>['siteId'=>'site','webId'=>'web','siteUrl'=>$s['site_url']]];
 else $body=['id'=>$s['site_id'],'webUrl'=>$s['site_url'],'displayName'=>'Central @ Tisser'];
 return ['status'=>200,'body'=>json_encode($body)];
};
$c=new SharePointGraphClient($s,$transport);
$c->withArticles('fake-secret',function($api){$item=$api('POST','/items',['fields'=>['GesTISSER_ID'=>'1']]);assert_import($item['id']==='1','JSON adapter');
 try{$api('GET','https://evil.example/items');throw new LogicException('foreign URL accepted');}catch(RuntimeException $e){assert_import(!($e instanceof LogicException),'foreign pagination blocked');}
 try{$api('DELETE','/items/1');throw new LogicException('delete accepted');}catch(RuntimeException $e){assert_import(!($e instanceof LogicException),'delete blocked');}
});
assert_import($writes===1,'only explicit write');$rp=new ReflectionProperty($c,'token');$rp->setAccessible(true);assert_import($rp->getValue($c)==='','token cleared');
echo "PASS: typed mapping, preview without writes, bounded create-only batches, unique keys, stale previews, collisions, uncertain POST reconciliation, scoped JSON adapter and token cleanup.\n";
