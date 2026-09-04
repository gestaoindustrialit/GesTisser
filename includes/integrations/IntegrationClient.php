<?php
require_once __DIR__.'/IntegrationSecurity.php';
class IntegrationClient
{
    private $pdo; public function __construct(PDO $pdo){$this->pdo=$pdo;}
    private function credentials(int $id): array { $s=$this->pdo->prepare('SELECT key_name,value_encrypted FROM integration_credentials WHERE integration_id=?');$s->execute([$id]);$out=[];foreach($s as $r)$out[$r['key_name']]=IntegrationSecurity::decrypt($r['value_encrypted']);return $out; }
    public function request(array $integration, string $method='GET', string $endpoint='', $body=null, array $extra=[]): array
    {
        $url=rtrim($integration['base_url'],'/').'/'.ltrim($endpoint,'/'); if(!IntegrationSecurity::safeUrl($url)) throw new RuntimeException('Destino bloqueado pela proteção SSRF.');
        $credentials=$this->credentials((int)$integration['id']); $headers=['Accept: application/json'];
        $s=$this->pdo->prepare('SELECT * FROM integration_headers WHERE integration_id=? AND kind="header"');$s->execute([$integration['id']]); foreach($s as $h)$headers[]=$h['key_name'].': '.($h['is_sensitive']?IntegrationSecurity::decrypt($h['value']):$h['value']);
        if($integration['auth_type']==='bearer' && !empty($credentials['token']))$headers[]='Authorization: Bearer '.$credentials['token'];
        elseif($integration['auth_type']==='basic')$headers[]='Authorization: Basic '.base64_encode(($credentials['username']??'').':'.($credentials['password']??''));
        elseif($integration['auth_type']==='api_key')$headers[]=($credentials['api_key_name']??'X-API-Key').': '.($credentials['api_key_value']??'');
        elseif($integration['auth_type']==='oauth2' && !empty($credentials['access_token']))$headers[]='Authorization: Bearer '.$credentials['access_token'];
        foreach($extra as $k=>$v)$headers[]=$k.': '.$v;
        $attempts=max(1,(int)$integration['retries']+1); $last=[];
        for($a=0;$a<$attempts;$a++) { $ch=curl_init($url); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_TIMEOUT=>(int)$integration['timeout'],CURLOPT_SSL_VERIFYPEER=>(bool)$integration['verify_ssl'],CURLOPT_HEADER=>true]); if($body!==null)curl_setopt($ch,CURLOPT_POSTFIELDS,$body); $raw=curl_exec($ch);$err=curl_error($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$hs=(int)curl_getinfo($ch,CURLINFO_HEADER_SIZE);curl_close($ch);$last=['ok'=>$err===''&&$code>=200&&$code<300,'status'=>$code,'headers'=>substr((string)$raw,0,$hs),'body'=>substr((string)$raw,$hs),'error'=>$err,'url'=>$url,'method'=>$method]; if($last['ok']||($code<500&&$code!==429))break; $delay=(int)$integration['retry_delay']; if($code===429 && preg_match('/Retry-After:\s*(\d+)/i',$last['headers'],$m))$delay=min(60,(int)$m[1]); if($a+1<$attempts)sleep(max(0,$delay)); }
        return $last;
    }
}
