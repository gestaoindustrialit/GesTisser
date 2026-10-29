<?php
require_once __DIR__.'/includes/assist_bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
try {
    $assist->demand('assist.use');
    $method=(string)($_SERVER['REQUEST_METHOD']??'GET');
    if($method==='GET') $result=['messages'=>$assist->history(),'suggestions'=>$assist->suggestions((string)($_GET['language']??'pt'),(array)($_GET['context']??[]))];
    elseif($method==='POST') {
        if(!validate_csrf_or_abort(false)) throw new RuntimeException('Pedido inválido (CSRF).',419);
        $action=(string)($_POST['action']??'ask');
        if($action==='ask') $result=$assist->ask((string)($_POST['question']??''),(string)($_POST['language']??'pt'),(int)($_POST['conversation_id']??0),(array)($_POST['context']??[]));
        elseif($action==='feedback') { $assist->feedback((int)($_POST['message_id']??0),(int)($_POST['useful']??-1));$result=['ok'=>true]; }
        elseif($action==='report') $result=['id'=>$assist->report($_POST)];
        else throw new InvalidArgumentException('Ação inválida.');
    } else throw new RuntimeException('Método não permitido.',405);
    echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP);
} catch(Throwable $e) {
    $status=$e instanceof InvalidArgumentException?422:(int)$e->getCode();
    if(!in_array($status,[403,404,405,409,419,422,429],true)) { $status=500;error_log('[Assist] '.get_class($e)); }
    http_response_code($status);echo json_encode(['error'=>$status===500?'Erro interno. Tente novamente.':$e->getMessage()],JSON_UNESCAPED_UNICODE);
}
