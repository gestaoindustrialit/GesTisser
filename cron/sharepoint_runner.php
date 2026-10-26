<?php
// Dedicated CLI entry point. Never use HTTP, sessions or application migrations.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(PHP_VERSION_ID!==70033){fwrite(STDERR,"Use PHP 7.0.33 CLI for this production runner.\n");exit(1);}
require_once dirname(__DIR__).'/bootstrap/config.php';
require_once dirname(__DIR__).'/app/Services/SharePointIntegration.php';
require_once dirname(__DIR__).'/app/Services/SharePointArticleImport.php';
require_once dirname(__DIR__).'/app/Services/SharePointDocumentSync.php';
require_once dirname(__DIR__).'/app/Services/SharePointSchedule.php';
if(!app_config('cron_enabled',false)||!app_config('external_services_enabled',false)){echo "SharePoint cron disabled in this environment.\n";exit(0);}
$path=app_config('db_path');
if(!is_file($path)){fwrite(STDERR,"Existing database unavailable; no database created.\n");exit(1);}
$lock=null;$exitCode=0;
try{
    $pdo=new PDO('sqlite:'.$path);$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys=ON');$pdo->exec('PRAGMA busy_timeout=5000');
    $scheduler=new SharePointSchedule($pdo);
    if(empty($scheduler->settings()['enabled'])){echo "SharePoint schedule disabled.\n";exit(0);}
    $settings=(new SharePointIntegration($pdo,app_config('paths.uploads')))->settings();
    if(!$scheduler->hasWork()){echo "No SharePoint work due.\n";exit(0);}
    $lock=fopen(sys_get_temp_dir().'/gestisser-sp-'.hash('sha256',$settings['site_id'].'/'.$settings['list_id']).'.lock','c');
    if(!$lock || !flock($lock,LOCK_EX|LOCK_NB)){echo "SharePoint operation already running.\n";exit(0);}
    $client=new SharePointGraphClient($settings);$secret=SharePointClientSecret::read();
    $check=$client->diagnose($secret);
    if(isset($check['error']))throw new RuntimeException('SharePoint connection validation failed. Run TESTAR SHAREPOINT in the administration page.');
    $status=$scheduler->run($settings,function($kind)use($pdo,$settings,$secret){
        return function($cursor,$maxId)use($pdo,$settings,$secret,$kind){
            $client=new SharePointGraphClient($settings);
            if($kind==='documents')return $client->withDocuments($secret,function($api)use($pdo,$settings,$cursor,$maxId){return(new SharePointDocumentSync($pdo,$api,app_config('paths.root'),$settings['drive_id']))->batch($cursor,$maxId);});
            return $client->withArticles($secret,function($api)use($pdo,$cursor,$maxId){return(new SharePointArticleImport($pdo,$api))->syncBatch($cursor,$maxId);},false);
        };
    });
    echo json_encode($status,JSON_UNESCAPED_UNICODE)."\n";
}catch(Throwable $e){fwrite(STDERR,json_encode(SharePointImportFailure::safe($e),JSON_UNESCAPED_UNICODE)."\n");$exitCode=1;}
finally{if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}}
exit($exitCode);
