<?php
require_once __DIR__.'/includes/assist_bootstrap.php';
require_once __DIR__.'/app/Services/AssistKnowledgeSpreadsheet.php';
header('Cache-Control: no-store');
try {
    $assist->demand('assist.edit');
    $path=AssistKnowledgeSpreadsheet::template($assist->categories());
    try {
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="modelo_conhecimento_SACO.xlsx"');
        header('Content-Length: '.filesize($path));readfile($path);
    } finally {@unlink($path);}
} catch(Throwable $e){
    $status=(int)$e->getCode()===403?403:503;http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $status===403?'Acesso não autorizado.':'Não foi possível gerar o modelo Excel. Verifique as extensões ZIP e SimpleXML do servidor.';
    error_log('[Assist template] '.get_class($e));
}
