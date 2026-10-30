<?php
require_once __DIR__.'/helpers.php';require_once __DIR__.'/app/Services/ColorimetryService.php';require_login();
gt_erp_run_phase1_migrations($pdo);ColorimetryService::migrate($pdo);$ink=new ColorimetryService($pdo,current_user($pdo));
try{
    $ink->authorize();$rows=$ink->query('SELECT a.*,f.status FROM erp_ink_formula_attachments a JOIN erp_ink_formulas f ON f.id=a.formula_id WHERE a.id=?',[(int)($_GET['id']??0)]);
    if(!$rows){http_response_code(404);exit('Anexo não encontrado.');}$a=$rows[0];
    if($a['status']!=='Aprovada'&&!$ink->can('ink.formula.create')&&!$ink->can('ink.formula.approve')){http_response_code(403);exit('Sem permissão.');}
    header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header('Content-Type: '.$a['mime']);header('Content-Disposition: attachment; filename="referencia-'.(int)$a['id'].($a['mime']==='application/pdf'?'.pdf':($a['mime']==='image/png'?'.png':'.jpg')).'"');echo $a['content'];
}catch(Throwable $e){http_response_code(403);exit('Sem permissão.');}
