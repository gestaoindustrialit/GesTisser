<?php
require __DIR__.'/../app/Services/SharePointIntegration.php';
function expect_sp($ok,$message) { if (!$ok) throw new RuntimeException($message); }
$s=['tenant_id'=>'11111111-1111-1111-1111-111111111111','client_id'=>'22222222-2222-2222-2222-222222222222','site_id'=>'host,site,web','site_url'=>'https://tisserpt.sharepoint.com','list_id'=>'articles','drive_id'=>'drive'];
$claims=['tid'=>$s['tenant_id'],'appid'=>$s['client_id'],'aud'=>'https://graph.microsoft.com','roles'=>['Sites.Selected'],'exp'=>time()+3600];
$token='e30.'.rtrim(strtr(base64_encode(json_encode($claims)),'+/','-_'),'=').'.signature';
$mode='403'; $calls=[];
$t=function ($url,$form,$bearer) use ($s,$token,&$mode,&$calls) {
 $calls[]=$url;
 if ($form!==null) return ['status'=>200,'body'=>json_encode(['access_token'=>$token])];
 expect_sp($bearer===$token,'fresh token');
 if ($mode==='403') return ['status'=>403,'body'=>json_encode(['error'=>['code'=>'accessDenied','message'=>'Access denied secret-value '.$token,'innerError'=>['request-id'=>'33333333-3333-3333-3333-333333333333']]])];
 if (strpos($url,'/columns')!==false) $body=['value'=>[]];
 elseif (strpos($url,'/lists/')!==false) $body=['id'=>'articles','webUrl'=>$s['site_url'].'/Lists/Artigos'];
 elseif (strpos($url,'/drives/')!==false) {
  $body=['id'=>'drive','driveType'=>'documentLibrary','folder'=>[],'parentReference'=>['driveId'=>'drive'],'sharepointIds'=>['siteId'=>'site','webId'=>'web','siteUrl'=>$s['site_url']]];
 } else $body=['id'=>$s['site_id'],'webUrl'=>$s['site_url'],'displayName'=>'Central @ Tisser'];
 return ['status'=>200,'body'=>json_encode($body)];
};
$run=function () use ($s,$t) { return (new SharePointGraphClient($s,$t))->diagnose('secret-value'); };
$r=$run(); expect_sp(count($calls)===6,'OAuth and five direct checks');
expect_sp($r['first_403_endpoint']==='GET /sites/{siteId}','first forbidden');
expect_sp($r['configuration']['client_id']===$s['client_id'],'existing configuration reported');
expect_sp($r['application_roles']===['Sites.Selected'],'actual token roles');
$j=json_encode($r); expect_sp(strpos($j,$token)===false && strpos($j,'secret-value')===false,'redaction');
expect_sp($r['requests'][1]['graph_message']==='Access denied [redigido] [redigido]','Graph message captured safely');
$mode='ok';$r=$run();expect_sp($r['state']==='Leitura validada; escrita não testada' && $r['library_verified'],'resource identities');
$s['site_url']='https://wrong.sharepoint.com';
$bad=(new SharePointGraphClient($s,$t))->diagnose('secret-value');
expect_sp($bad['state']==='Erro','different site URL rejected');
echo "PASS: direct checks, stored IDs, Graph message redaction, role inspection, first 403 and site/library identity.\n";
