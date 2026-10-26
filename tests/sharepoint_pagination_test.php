<?php
require __DIR__.'/../app/Services/SharePointIntegration.php';
require __DIR__.'/../app/Services/SharePointArticleImport.php';
function pagination_check($ok,$label){if(!$ok)throw new RuntimeException($label);}
$s=['tenant_id'=>'11111111-1111-1111-1111-111111111111','client_id'=>'22222222-2222-2222-2222-222222222222','site_id'=>'tisserpt.sharepoint.com,aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa,bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb','list_id'=>'cccccccc-cccc-cccc-cccc-cccccccccccc'];
$base='https://graph.microsoft.com/v1.0/sites/'.$s['site_id'].'/lists/'.$s['list_id'];
$next=$base.'/items?$skiptoken=Paged%3dTRUE%26p_ID%3d200';$requests=[];
$transport=function($url,$form)use(&$requests,$next){
 $requests[]=$url;
 if($form!==null)return['status'=>200,'body'=>'{"access_token":"secret-token"}'];
 if($url===$next)$body=['value'=>[['id'=>'201','fields'=>['GesTISSER_ID'=>'201']]]];
 else {$rows=[];for($i=1;$i<=200;$i++)$rows[]=['id'=>(string)$i,'fields'=>['GesTISSER_ID'=>(string)$i]];$body=['value'=>$rows,'@odata.nextLink'=>$next];}
 return['status'=>200,'body'=>json_encode($body)];
};
$c=new SharePointGraphClient($s,$transport);
$c->withArticles('secret',function($api)use($next,$base){
 $first=$api('GET','/items?$top=200');$second=$api('GET',$first['@odata.nextLink']);
 pagination_check(count($first['value'])===200&&$second['value'][0]['id']==='201','second page with literal commas');
 $api('GET',str_replace(',','%2C',$base).'/columns?$skiptoken=opaque%2Btoken');
 foreach(['https://evil.example/v1.0/sites/x/lists/y/items',$base.'/../items',$base.'-other/items',str_replace('https://','http://',$base).'/items',str_replace('/lists/','/drives/',$base).'/items',$base.'/items/1',$base.'/items#fragment','https://graph.microsoft.com@evil.example/items']as$bad){
  try{$api('GET',$bad);throw new LogicException('unsafe nextLink accepted');}catch(RuntimeException$e){pagination_check(!($e instanceof LogicException),'unsafe target rejected');}
 }
},false);
pagination_check(in_array($next,$requests,true),'opaque nextLink preserved byte for byte');
$details=SharePointImportFailure::safe(new RuntimeException('Paginação Graph rejeitada: destino fora do site/lista configurados.'));
pagination_check(strpos($details['local_error'],'Paginação Graph')===0,'pagination failures visible');
echo "PASS: 200+1 pagination, encoded/literal IDs, opaque skiptoken and cross-site/host/path rejection.\n";
