<?php
require __DIR__.'/sharepoint_article_bulk_test.php';
require __DIR__.'/../app/Services/SharePointIntegration.php';
$jobs->cancel($jobs->load()['run']);
$body=json_encode(['error'=>['code'=>'activityLimitReached','message'=>'PRIVATE_BODY token SECRET','innerError'=>['request-id'=>'33333333-3333-3333-3333-333333333333']]]);
$failure=new SharePointImportFailure('GET /sites/{siteId}/lists/{listId}/items',['status'=>429,'body'=>$body,'headers'=>['Retry-After'=>'60']]);
$job=$jobs->start('configuration');$run=$job['run'];$calls=0;
$fail=function()use($failure,&$calls){++$calls;throw $failure;};
$job=$jobs->step($run,'configuration',$fail);
bulk_check($job['last_error']['http_status']===429 && $job['last_error']['graph_error_code']==='activityLimitReached' && $job['last_error']['request_id']==='33333333-3333-3333-3333-333333333333','protocol metadata persisted');
bulk_check($job['cursor']===0&&$job['retry_at']>=time()+59,'cursor and retry-after preserved');
$jobs->step($run,'configuration',$fail);bulk_check($calls===1,'wait respected');
for($i=0;$i<3;$i++){
 $job=$jobs->load();$job['retry_at']=time()-1;
 $pdo->prepare('UPDATE app_settings SET setting_value=? WHERE setting_key=?')->execute([json_encode($job),'sharepoint.article_job.v1']);
 $job=$jobs->step($run,'configuration',$fail);
}
bulk_check(!isset($job['retry_at'])&&$job['retry_count']===3,'automatic retries bounded');
$serialized=json_encode($jobs->load());bulk_check(strpos($serialized,'PRIVATE_BODY')===false&&strpos($serialized,'SECRET')===false,'no remote prose or secrets persisted');
$jobs->resume($run,'configuration');
$job=$jobs->step($run,'configuration',function(){throw new RuntimeException('Coluna incompatível: DataUltimaProducao.');});
bulk_check($job['last_error']['local_error']==='Coluna incompatível: DataUltimaProducao.'&&!isset($job['retry_at']),'actionable schema diagnosis with no endless retry');
$client=new SharePointGraphClient(['tenant_id'=>'11111111-1111-1111-1111-111111111111','client_id'=>'22222222-2222-2222-2222-222222222222','site_id'=>'site','list_id'=>'list'],function($url,$form)use($body){
 if($form!==null)return['status'=>200,'body'=>'{"access_token":"PRIVATE_TOKEN"}'];
 return['status'=>503,'body'=>$body,'headers'=>['request-id'=>'44444444-4444-4444-4444-444444444444','retry-after'=>'5']];
});
try{$client->withArticles('PRIVATE_SECRET',function($api){$api('GET','/columns');},false);throw new LogicException('failure missed');}
catch(SharePointImportFailure $e){$d=$e->diagnostic();bulk_check($d['http_status']===503&&$d['request_id']==='44444444-4444-4444-4444-444444444444'&&$d['endpoint']==='GET /sites/{siteId}/lists/{listId}/columns','scoped client emits safe structured diagnostics');}
$reflection=new ReflectionProperty($client,'token');$reflection->setAccessible(true);bulk_check($reflection->getValue($client)==='','token cleared on structured failure');
echo "PASS: HTTP metadata, correlation ID, Retry-After, bounded retries, schema errors, redaction, preserved cursor and token cleanup.\n";
