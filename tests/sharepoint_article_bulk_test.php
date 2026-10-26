<?php
require __DIR__.'/../app/Services/SharePointArticleImport.php';
require __DIR__.'/../app/Services/SharePointArticleJob.php';
function bulk_check($ok,$label){if(!$ok)throw new RuntimeException($label);}
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE app_settings(setting_key TEXT PRIMARY KEY,setting_value TEXT);
CREATE TABLE erp_finished_products(id INTEGER PRIMARY KEY,code TEXT,description TEXT,status TEXT,updated_at TEXT,customer_id INTEGER);
CREATE TABLE erp_customers(id INTEGER PRIMARY KEY,code TEXT,name TEXT);
CREATE TABLE erp_product_documents(id INTEGER PRIMARY KEY,entity_type TEXT,entity_id INTEGER,status TEXT);');
$insert=$pdo->prepare('INSERT INTO erp_finished_products VALUES(?,?,?,?,?,NULL)');
for($i=1;$i<=1600;$i++)$insert->execute([$i,'A'.$i,'Artigo '.$i,'Ativo','2026-10-26 12:00:00']);
$columns=[];foreach(['Title','Descri_x00e7__x00e3_o','CodigoCliente','Cliente','NumeroDocumentos','EstadoSincronizacao','GesTISSER_ID']as$name)$columns[]=['name'=>$name,'text'=>['maxLength'=>255],'indexed'=>true,'enforceUniqueValues'=>true];
$columns[]=['name'=>'Estado','boolean'=>[]];$columns[]=['name'=>'DataAtualizacao','dateTime'=>[]];
$columns[]=['name'=>'DataUltimaProducao','dateTime'=>[]];
$remote=[];$posts=0;$patches=0;$conflict=false;$uncertain=false;
$api=function($method,$path,$payload)use(&$remote,&$columns,&$posts,&$patches,&$conflict,&$uncertain){
 if($method==='GET')return['value'=>$path==='/columns'?$columns:array_values($remote)];
 if($method==='POST'){
  $id=(string)$payload['fields']['GesTISSER_ID'];
  bulk_check(!isset($remote[$id]),'unique key');++$posts;
  $remote[$id]=['id'=>$id,'eTag'=>'"v1"','fields'=>$payload['fields']];
  if($uncertain){$uncertain=false;throw new RuntimeException('timeout after write');}
  return$remote[$id];
 }
 bulk_check($method==='PATCH','no deletes');preg_match('~/items/([0-9]+)/fields~',$path,$m);$id=$m[1];
 bulk_check($payload['etag']===$remote[$id]['eTag'],'optimistic concurrency');
 if($conflict){$conflict=false;throw new RuntimeException('HTTP 412');}
 ++$patches;$remote[$id]['fields']=$payload['fields'];$remote[$id]['eTag']='"v2"';return$payload['fields'];
};
$batch=function($cursor,$max)use($pdo,$api){return(new SharePointArticleImport($pdo,$api))->syncBatch($cursor,$max);};
$jobs=new SharePointArticleJob($pdo);$job=$jobs->start('configuration');$run=$job['run'];
// A creation with a lost response is discovered on resume, not blindly repeated.
$uncertain=true;$job=$jobs->step($run,'configuration',$batch);bulk_check($job['state']==='paused'&&$job['cursor']===0&&$posts===1,'uncertain batch paused before advancing');
$jobs->resume($run,'configuration');
for($i=0;$i<100&&$jobs->load()['state']==='running';$i++){
 $jobs=new SharePointArticleJob($pdo);$job=$jobs->step($run,'configuration',$batch);
}
bulk_check($job['state']==='complete'&&$job['processed']===1600&&count($remote)===1600&&$posts===1600,'1600 rows complete across resumable steps without duplicate writes');
$job=$jobs->start('configuration');$run=$job['run'];$pdo->exec('UPDATE erp_finished_products SET description="Alterado",code="NOVO1" WHERE id=1');
$conflict=true;$job=$jobs->step($run,'configuration',$batch);bulk_check($job['state']==='paused'&&$patches===0,'concurrent update stops safely');
$jobs->resume($run,'configuration');
for($i=0;$i<100&&$jobs->load()['state']==='running';$i++)$job=$jobs->step($run,'configuration',$batch);
bulk_check($patches===1&&$posts===1600&&$job['updated']===1&&$job['unchanged']===1599,'only changed fields updated by stable ID, including renamed code');
$job=$jobs->start('configuration');$run=$job['run'];
$job=$jobs->pause($run,'configuration');$cursor=$job['cursor'];$job=$jobs->step($run,'configuration',$batch);bulk_check($job['cursor']===$cursor,'paused job performs no work');
try{$jobs->resume($run,'different');throw new LogicException('wrong configuration accepted');}catch(RuntimeException$e){bulk_check(!($e instanceof LogicException),'changed destination blocked');}
$job=$jobs->cancel($run);bulk_check($job['state']==='cancelled'&&count($remote)===1600,'cancel preserves remote rows');
// Blocked rows are counted and reported without stopping other valid articles.
$pdo->prepare('UPDATE erp_finished_products SET description=? WHERE id=2')->execute([str_repeat('x',256)]);
$job=$jobs->start('configuration');$run=$job['run'];$job=$jobs->step($run,'configuration',$batch);
bulk_check($job['blocked']===1&&$job['errors'][0]['id']===2,'blocked row counted with reason');
echo "PASS: 1600 articles, durable resume, uncertain creates, changed-code updates, ETag conflicts, unchanged records, pause, configuration binding, cancel and blocked rows.\n";
