<?php
require __DIR__.'/../app/Services/SharePointIntegration.php';
function check($value,$message) { if (!$value) throw new RuntimeException($message); }
$pdo=new PDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE app_settings(setting_key TEXT PRIMARY KEY,setting_value TEXT)');
$dir=sys_get_temp_dir().'/gestisser-sp-'.bin2hex(random_bytes(6)); mkdir($dir,0700);
try {
    $integration=new SharePointIntegration($pdo,$dir);
    check($integration->overview()['state']==='Por configurar','initial listing state');
    $settings=$integration->settings(); $settings['tenant_id']=$settings['client_id']='11111111-1111-1111-1111-111111111111'; $settings['drive_id']='drive';
    $integration->save($settings); check($integration->settings()['drive_id']==='drive','settings roundtrip');
    check($integration->overview()['state']==='Não testado','saved listing state');
    $before=scandir($dir); $result=$integration->testStorage(); check($result['state']==='Ligado','local read/write'); check(scandir($dir)===$before,'temporary cleanup');
    $bad=$settings; $bad['site_url']='https://localhost';
    try { $integration->save($bad); throw new LogicException('SSRF accepted'); } catch (RuntimeException $e) { check(!($e instanceof LogicException),'SSRF rejected'); }
    $result=$integration->diagnostic('graph',function () { throw new RuntimeException('permission failure'); });
    check($result['state']==='Erro' && $integration->lastDiagnostic('graph')['error']==='permission failure','persist failure');
    check($integration->overview()['state']==='Erro','error visible in listing');
    $settings['site_id']='changed'; $integration->save($settings);
    check($integration->lastDiagnostic('graph')===[] && $integration->overview()['state']==='Não testado','configuration change invalidates results');
    $settings['site_id']='';
    $calls=[];
    $transport=function ($url,$form) use (&$calls) {
        $calls[]=$url;
        if (strpos($url,'login.microsoftonline.com')!==false) return ['access_token'=>'test-token'];
        if (strpos($url,'sharepoint.com:')!==false) return ['id'=>'site'];
        if (substr($url,-6)==='/lists') return ['value'=>[], '@odata.nextLink'=>'https://graph.microsoft.com/v1.0/sites/site/lists?page=2'];
        if (strpos($url,'page=2')!==false) return ['value'=>[['id'=>'articles','webUrl'=>'https://tisserpt.sharepoint.com/Lists/Artigos']]];
        if (substr($url,-8)==='/columns') return ['value'=>[['name'=>'Title','displayName'=>'Título','text'=>new stdClass()]]];
        if (substr($url,-7)==='/drives') return ['value'=>[['id'=>'drive']]];
        if (substr($url,-5)==='/root') return ['id'=>'root'];
        throw new RuntimeException('Unexpected route');
    };
    $result=(new SharePointGraphClient($settings,$transport))->diagnose('fake-secret');
    check($result['list_id']==='articles' && $result['write_verified']===false && count($calls)===7,'full read-only discovery and pagination');
    $integration->diagnostic('graph',function () use ($result) { return $result; });
    check($integration->overview()['state']==='Leitura validada' && $integration->overview()['style']==='warning','reading never means synchronisation active');
    $settings['list_id']='wrong';
    try { (new SharePointGraphClient($settings,$transport))->diagnose('fake-secret'); throw new LogicException('wrong list accepted'); } catch (RuntimeException $e) { check(!($e instanceof LogicException),'wrong list rejected'); }
    $settings['list_id']=''; $settings['drive_id']='missing';
    try { (new SharePointGraphClient($settings,$transport))->diagnose('fake-secret'); throw new LogicException('missing drive accepted'); } catch (RuntimeException $e) { check(!($e instanceof LogicException),'missing drive rejected'); }
    check(strpos(json_encode($integration->settings()),'fake-secret')===false,'no persisted secret');
    echo "PASS: configuration, URL validation, local integrity and cleanup, diagnostic persistence, Graph discovery/pagination, mismatched list, missing drive, no false write success, no persisted secret.\n";
} finally { rmdir($dir); }
