<?php
require __DIR__.'/../app/Services/SharePointDocumentSync.php';
require __DIR__.'/../app/Services/SharePointArticleJob.php';
require __DIR__.'/../app/Services/SharePointIntegration.php';
function doc_check($ok,$label){if(!$ok)throw new RuntimeException($label);}
$root=sys_get_temp_dir().'/sp-doc-'.bin2hex(random_bytes(8));mkdir($root);mkdir($root.'/storage');mkdir($root.'/storage/uploads');
file_put_contents($root.'/storage/uploads/one.pdf','%PDF-1.4 test-content');
file_put_contents($root.'/outside.pdf','private');symlink($root.'/outside.pdf',$root.'/storage/uploads/escape.pdf');
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE app_settings(setting_key TEXT PRIMARY KEY,setting_value TEXT);
CREATE TABLE erp_finished_products(id INTEGER PRIMARY KEY);
CREATE TABLE erp_product_documents(id INTEGER PRIMARY KEY,entity_type TEXT,entity_id INTEGER,status TEXT,title TEXT,file_url TEXT);
INSERT INTO erp_finished_products VALUES(79);
INSERT INTO erp_product_documents VALUES(1,"finished_product",79,"Ativo","Maquete","storage/uploads/one.pdf");');
$items=[];$uploads=0;$folders=0;$lost=false;
$api=function($method,$path,$payload)use(&$items,&$uploads,&$folders,&$lost){
 if($method==='GET'){
  if(isset($items[$path]))return$items[$path];
  throw new SharePointImportFailure('GET /drives/{driveId}/{resource}',['status'=>404,'body'=>'{}']);
 }
 if($method==='POST'){
  ++$folders;$p=$path==='/root/children'?'/root:/'.$payload['name']:substr($path,0,-10).'/'.$payload['name'];
  return$items[$p]=['id'=>'folder'.$folders,'folder'=>[]];
 }
 doc_check($method==='PUT'&&is_resource($payload),'stream uploads only');
 rewind($payload);$bytes=stream_get_contents($payload);++$uploads;$p=substr($path,0,-9);
 $item=['id'=>'file'.$uploads,'eTag'=>'v'.$uploads,'size'=>strlen($bytes),'file'=>['hashes'=>['sha1Hash'=>sha1($bytes)]]];
 $items[$p]=$item;
 if($lost){$lost=false;throw new SharePointImportFailure('PUT /drives/{driveId}/{resource}',['status'=>0,'connection_error'=>true]);}
 return$item;
};
try{
 $service=new SharePointDocumentSync($pdo,$api,$root,'drive');$r=$service->batch(0,99);
 doc_check($r[0]['result']==='Criado'&&$uploads===1&&$folders===3,'folders and binary upload');
 doc_check(isset($items['/root:/Artigos/Maquetes/79']),'stable article folder');
 $r=$service->batch(0,99);doc_check($r[0]['result']==='Sem alterações'&&$uploads===1,'unchanged content skipped');
 file_put_contents($root.'/storage/uploads/one.pdf','%PDF-1.4 revised-content');$lost=true;
 $r=$service->batch(0,99);doc_check(strpos($r[0]['result'],'Não confirmado')===0&&$uploads===2,'lost response pauses');
 $r=$service->batch(0,99);doc_check($r[0]['result']==='Sem alterações'&&$uploads===2,'remote SHA1 reconciles lost upload');
 $files=array_filter(array_keys($items),function($p){return substr($p,-4)==='.pdf';});doc_check(count($files)===2,'changed content preserves old version');
 foreach($items as $p=>&$item)if(isset($item['file'])){$item['eTag']='external-change';$item['file']['hashes']=[];}unset($item);
 $r=$service->batch(0,99);doc_check($r[0]['result']==='Bloqueado'&&$uploads===2,'unverified external changes not overwritten');
 $pdo->exec('UPDATE erp_product_documents SET file_url="storage/uploads/escape.pdf" WHERE id=1');
 doc_check($service->batch(0,99)[0]['result']==='Bloqueado','symlink outside uploads blocked');
 $pdo->exec('UPDATE erp_product_documents SET file_url="storage/uploads/../outside.pdf" WHERE id=1');
 doc_check($service->batch(0,99)[0]['result']==='Bloqueado','path traversal blocked');
 $jobs=new SharePointArticleJob($pdo,'documents');$job=$jobs->start('docs');doc_check($job['total']===1,'independent document job counts');
 doc_check((new SharePointArticleJob($pdo))->load()===[],'article progress untouched');
 $s=['tenant_id'=>'11111111-1111-1111-1111-111111111111','client_id'=>'22222222-2222-2222-2222-222222222222','site_id'=>'host,site,web','drive_id'=>'drive','list_id'=>'list'];$requests=[];
 $client=new SharePointGraphClient($s,function($url,$form,$token,$kind=null)use(&$requests){
  $requests[]=$url;
  if($form!==null && $kind===null)return['status'=>200,'body'=>'{"access_token":"secret"}'];
  if(strpos($url,'/root?')!==false)return['status'=>200,'body'=>'{"sharepointIds":{"siteId":"site","webId":"web"}}'];
  return['status'=>201,'body'=>'{"id":"folder","folder":{}}'];
 });
 $client->withDocuments('secret',function($api){
  $api('POST','/root:/Artigos/Maquetes:/children',['name'=>'79','folder'=>new stdClass()]);
  foreach([['DELETE','/root:/Artigos/Maquetes/79/test.pdf'],['GET','https://evil.example/file'],['GET','/root:/../secret'],['GET','/root:/Other/file.pdf']]as$bad){
   try{$api($bad[0],$bad[1]);throw new LogicException('scope escaped');}catch(RuntimeException$e){doc_check(!($e instanceof LogicException),'scoped drive rejects unrelated operation');}
  }
 });
 echo "PASS: real file snapshot, folders, upload confirmation, checksums, repeat skips, immutable versions, lost-response reconciliation, path safety, separate jobs and drive scope.\n";
}finally{unlink($root.'/storage/uploads/escape.pdf');unlink($root.'/storage/uploads/one.pdf');unlink($root.'/outside.pdf');rmdir($root.'/storage/uploads');rmdir($root.'/storage');rmdir($root);}
