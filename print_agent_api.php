<?php
require_once __DIR__.'/config.php';
require_once __DIR__.'/app/Services/CentralPrinting.php';
header('Cache-Control: no-store');header('Content-Type: application/json; charset=utf-8');
// Trust the web server TLS indicator only, never a client supplied Forwarded header.
if(empty($_SERVER['HTTPS'])||$_SERVER['HTTPS']==='off'){http_response_code(403);exit(json_encode(['error'=>'HTTPS obrigatório.']));}
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit(json_encode(['error'=>'POST obrigatório.']));}
if((int)($_SERVER['CONTENT_LENGTH']??0)>65536){http_response_code(413);exit('{}');}
$printing=new CentralPrinting($pdo);
if(!$printing->installed()){http_response_code(503);exit(json_encode(['error'=>'Sistema não preparado.']));}
try{
    $authorization=(string)($_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'');
    if(!preg_match('/^Bearer ([a-f0-9]{64})$/D',$authorization,$match))throw new RuntimeException('Credenciais inválidas.');
    $agent=(string)($_SERVER['HTTP_X_GESTISSER_AGENT']??'');$printing->authenticate($agent,$match[1]);
}catch(Throwable $e){http_response_code(401);exit(json_encode(['error'=>'Credenciais inválidas.']));}
try{
    $stream=fopen('php://input','rb');$raw=stream_get_contents($stream,65537);fclose($stream);if(strlen($raw)>65536)throw new InvalidArgumentException('Pedido demasiado grande.');
    $body=json_decode($raw,true);if(!is_array($body))throw new InvalidArgumentException('JSON inválido.');
    $action=(string)($body['action']??'');
    if($action==='heartbeat'){$printing->heartbeat($agent,(array)($body['printers']??[]),(string)($body['version']??''));$result=['ok'=>true];}
    elseif($action==='claim')$result=['job'=>$printing->claim($agent)];
    elseif($action==='content'){
        $j=$printing->content($agent,(string)($body['id']??''),(string)($body['lease_token']??''));header('Content-Type: '.$j['mime']);echo $j['content'];exit;
    }elseif($action==='report'){$printing->report($agent,(string)($body['id']??''),(string)($body['lease_token']??''),(string)($body['state']??''),(string)($body['error']??''),(string)($body['spool_id']??''));$result=['ok'=>true];}
    else throw new InvalidArgumentException('Ação inválida.');
    echo json_encode($result);
}catch(InvalidArgumentException $e){http_response_code(422);echo json_encode(['error'=>$e->getMessage()]);}
catch(RuntimeException $e){http_response_code(409);echo json_encode(['error'=>'Operação recusada. Consulte a fila.']);}
catch(Throwable $e){error_log('CentralPrinting API: '.$e->getMessage());http_response_code(500);echo json_encode(['error'=>'Erro interno.']);}
