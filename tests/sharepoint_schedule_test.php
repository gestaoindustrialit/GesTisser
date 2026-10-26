<?php
require __DIR__.'/../app/Services/SharePointSchedule.php';
function schedule_check($ok,$label){if(!$ok)throw new RuntimeException($label);}
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE app_settings(setting_key TEXT PRIMARY KEY,setting_value TEXT);
CREATE TABLE erp_finished_products(id INTEGER PRIMARY KEY);
CREATE TABLE erp_product_documents(id INTEGER PRIMARY KEY,entity_id INTEGER,entity_type TEXT,status TEXT);
INSERT INTO erp_finished_products VALUES(1);
INSERT INTO erp_product_documents VALUES(1,1,"finished_product","Ativo");');
$scheduler=new SharePointSchedule($pdo);$time=1800000000;
$clock=function()use(&$time){return$time;};$calls=['articles'=>0,'documents'=>0];
$factory=function($kind)use(&$calls){return function($cursor,$max)use(&$calls,$kind){++$calls[$kind];return$cursor<$max?[['id'=>$max,'code'=>'fixture','result'=>'Criado','error'=>null]]:[];};};
$config=['site_id'=>'site','list_id'=>'list','drive_id'=>'drive'];
schedule_check($scheduler->settings()['articles_minutes']===60&&$scheduler->settings()['documents_minutes']===1440,'requested defaults');
schedule_check(!$scheduler->hasWork($time),'disabled initially');$scheduler->run($config,$factory,45,$clock);schedule_check(array_sum($calls)===0,'no work when disabled');
$scheduler->save(['enabled'=>'1','articles_minutes'=>60,'documents_minutes'=>1440]);
$scheduler->run($config,$factory,45,$clock);schedule_check($calls===['articles'=>1,'documents'=>1],'initial article/document sync');
schedule_check(!$scheduler->hasWork($time+3599),'no early hourly sync');
$time+=3600;$scheduler->run($config,$factory,45,$clock);schedule_check($calls===['articles'=>2,'documents'=>1],'hourly articles only');
$time=1800000000+86400;$scheduler->run($config,$factory,45,$clock);schedule_check($calls===['articles'=>3,'documents'=>2],'daily document interval');
// Explicit web pause is not silently resumed by cron.
$jobs=new SharePointArticleJob($pdo);$job=$jobs->start(hash('sha256',json_encode($config)));$jobs->pause($job['run'],$job['configuration']);
$before=$calls;$scheduler->run($config,$factory,45,$clock);schedule_check($calls===$before,'manual pause respected');
$jobs->resume($job['run'],$job['configuration']);$scheduler->run($config,$factory,45,$clock);schedule_check($calls['articles']===$before['articles']+1,'pending execution resumed');
// Permanent errors require review rather than recurring blind retries.
$time+=3600;$bad=function($kind){return function(){throw new SharePointImportFailure('GET /items',['status'=>403,'body'=>'{"error":{"code":"accessDenied"}}']);};};
$scheduler->run($config,$bad,45,$clock);$before=$calls;
schedule_check((new SharePointArticleJob($pdo))->load()['state']==='paused','permanent failure paused');
$scheduler->run($config,$factory,45,$clock);schedule_check($calls===$before,'permanent failure not retried automatically');
try{$scheduler->save(['enabled'=>1,'articles_minutes'=>0,'documents_minutes'=>1440]);throw new LogicException('invalid interval');}catch(RuntimeException$e){schedule_check(!($e instanceof LogicException),'interval validation');}
$scheduler->save(['articles_minutes'=>60,'documents_minutes'=>1440]);schedule_check(!$scheduler->hasWork($time+999999),'disabled schedule stops all automated work');
$old=(new SharePointArticleJob($pdo))->load();(new SharePointArticleJob($pdo))->cancel($old['run']);
$pdo->exec('INSERT INTO erp_finished_products VALUES(2),(3)');
$scheduler->save(['enabled'=>1,'articles_minutes'=>60,'documents_minutes'=>1440]);
$one=function($kind){return function($cursor,$max){return $cursor<$max?[['id'=>$cursor+1,'code'=>'fixture','result'=>'Criado','error'=>null]]:[];};};
$ticking=function()use(&$time){return$time++;};
$scheduler->run($config,$one,4,$ticking);
$pending=(new SharePointArticleJob($pdo))->load();schedule_check($pending['state']==='running'&&$pending['cursor']===1,'budget leaves durable cursor');
(new SharePointSchedule($pdo))->run($config,$one,45,$clock);
$finished=(new SharePointArticleJob($pdo))->load();schedule_check($finished['run']===$pending['run']&&$finished['state']==='complete'&&$finished['cursor']===3,'next cron tick resumes same run');
echo "PASS: hourly articles, daily documents, opt-in scheduling, due checks, durable jobs, manual pause, permanent errors and interval validation.\n";
