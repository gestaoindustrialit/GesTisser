<?php
require __DIR__.'/../app/Services/SharePointIntegration.php';
function check($value,$message) { if (!$value) throw new RuntimeException($message); }
$pdo=new PDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE app_settings(setting_key TEXT PRIMARY KEY,setting_value TEXT)');
$dir=sys_get_temp_dir().'/gestisser-sp-'.bin2hex(random_bytes(6)); mkdir($dir,0700);
try {
    $integration=new SharePointIntegration($pdo,$dir);
    check($integration->overview()['state']==='Por configurar','initial state');
    $settings=$integration->settings();
    $settings['tenant_id']=$settings['client_id']='11111111-1111-1111-1111-111111111111';
    $settings['site_id']='tisserpt.sharepoint.com,aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa,bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';
    $settings['list_id']='articles'; $settings['drive_id']='drive';
    $integration->save($settings);
    check($integration->overview()['state']==='Não testado','saved state');
    $before=scandir($dir); check($integration->testStorage()['state']==='Ligado','local integrity');
    check(scandir($dir)===$before,'cleanup');
    $bad=$settings; $bad['site_url']='https://localhost';
    try { $integration->save($bad); throw new LogicException('SSRF accepted'); }
    catch (RuntimeException $e) { check(!($e instanceof LogicException),'SSRF rejected'); }
    $base='https://graph.microsoft.com/v1.0';
    $sitePath=$base.'/sites/'.rawurlencode($settings['site_id']);
    $listPath=$sitePath.'/lists/articles'; $drivePath=$base.'/drives/drive';
    $driveMetadataPath=$drivePath.'?$select=id,driveType,sharepointIds';
    $rootPath=$drivePath.'/root?$select=id,folder,parentReference,sharepointIds';
    $ids=['siteId'=>'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa','webId'=>'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb','siteUrl'=>$settings['site_url']];
    $jwt='e30.'.rtrim(strtr(base64_encode(json_encode(['roles'=>['Sites.Selected']])), '+/', '-_'),'=').'.signature';
    $responses=[
        $sitePath=>['id'=>$settings['site_id'],'webUrl'=>$settings['site_url']],
        $listPath=>['id'=>'articles','webUrl'=>$settings['site_url'].'/Lists/Artigos'],
        $listPath.'/columns'=>['value'=>[['name'=>'Title','defaultValue'=>['value'=>'sensitive-column-default']]]],
        $driveMetadataPath=>['id'=>'drive','driveType'=>'documentLibrary','sharepointIds'=>$ids],
        $rootPath=>['id'=>'root','folder'=>[],'parentReference'=>['driveId'=>'drive'],'sharepointIds'=>$ids]
    ];
    $calls=[]; $failures=[]; $token=$jwt;
    $rid='cccccccc-cccc-cccc-cccc-cccccccccccc';
    $transport=function ($url,$form) use (&$calls,&$responses,&$failures,&$token,$rid) {
        $calls[]=$url;
        if (strpos($url,'login.microsoftonline.com')!==false) {
            check($form['grant_type']==='client_credentials' && $form['scope']==='https://graph.microsoft.com/.default','OAuth unchanged');
            return ['status'=>200,'body'=>json_encode(['access_token'=>$token])];
        }
        check($form===null,'Graph GET only');
        check(isset($responses[$url]),'no discovery or extra endpoints');
        if (isset($failures[$url])) return $failures[$url];
        return ['status'=>200,'body'=>json_encode($responses[$url]),'headers'=>['Request-Id'=>$rid]];
    };
    $run=function () use ($settings,$transport) { return (new SharePointGraphClient($settings,$transport))->diagnose('fake-secret'); };
    $result=$run();
    check($result['state']==='Leitura validada; escrita não testada' && count($calls)===6,'six exact requests');
    check($result['sites_selected_present'] && $result['column_count']===1 && !$result['write_verified'],'claims and no write');
    $integration->diagnostic('graph',function () use ($result) { return $result; });
    check($integration->overview()['state']==='Leitura validada','overview');
    foreach (array_keys($responses) as $failed) {
        $calls=[];
        $failures=[$failed=>['status'=>403,'body'=>json_encode(['error'=>['code'=>'accessDenied','message'=>'sensitive-error','innerError'=>['request-id'=>$rid]]])]];
        $result=$integration->diagnostic('graph',$run);
        check($result['state']==='Erro' && $result['first_403_endpoint']===$failed && count($result['requests'])===6,'first 403 with all independent results');
        $hit=null; foreach ($result['requests'] as $request) if ($request['endpoint']===$failed) $hit=$request;
        check($hit['http_status']===403 && $hit['graph_error_code']==='accessDenied' && $hit['request_id']===$rid,'safe Graph metadata');
        $persisted=json_encode($integration->lastDiagnostic('graph'));
        foreach ([$jwt,'fake-secret','sensitive-error','sensitive-column-default'] as $private) check(strpos($persisted,$private)===false,'no sensitive persistence');
    }
    $failures[$sitePath]=['status'=>403,'body'=>'{}'];
    check($run()['first_403_endpoint']===$sitePath,'earliest of multiple forbidden endpoints');
    $failures=[];
    $responses[$listPath]['webUrl']=$settings['site_url'].'/Lists/Other';
    check($run()['state']==='Erro','wrong list rejected');
    $responses[$listPath]['webUrl']=$settings['site_url'].'/Lists/Artigos';
    $responses[$driveMetadataPath]['sharepointIds']['webId']='other';
    $responses[$rootPath]['sharepointIds']['webId']='other';
    check($run()['state']==='Erro','foreign library rejected');
    $responses[$driveMetadataPath]['sharepointIds']=$ids;
    check($run()['state']==='Erro','contradictory root identity rejected');
    $responses[$rootPath]['sharepointIds']=$ids;
    $token='e30.'.base64_encode('{"roles":["Other"]}').'.sig';
    check(!$run()['sites_selected_present'] && $run()['state']==='Erro','missing role rejected');
    $token='opaque-token'; check(!$run()['roles_checked'],'unreadable token reported');
    $token=$jwt;
    $failures[$sitePath]=['status'=>401,'body'=>'{"error":{"code":"InvalidAuthenticationToken","message":"sensitive"}}'];
    check($run()['first_403_endpoint']===null && $run()['state']==='Erro','401 distinguished');
    $failures[$sitePath]=['status'=>200,'body'=>'invalid-json'];
    check($run()['state']==='Erro','invalid JSON');
    $throwing=function () { throw new RuntimeException('sensitive-transport-secret'); };
    $result=(new SharePointGraphClient($settings,$throwing))->diagnose('fake-secret');
    check($result['state']==='Erro' && strpos(json_encode($result),'sensitive-transport-secret')===false,'transport redaction');
    $settings['site_id']=''; $calls=[];
    check((new SharePointGraphClient($settings,$transport))->diagnose('fake-secret')['state']==='Erro' && !$calls,'missing ID prevents requests');
    $integration->save($settings); check($integration->lastDiagnostic('graph')===[],'changed configuration invalidates diagnostic');
    echo "PASS: exact read-only endpoints, OAuth, roles, site/list/library validation, each first 403, HTTP metadata, persistence/redaction, transport/JSON errors, storage.\n";
} finally { rmdir($dir); }
