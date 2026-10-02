<?php
declare(strict_types=1);

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/erp_migrations.php';
require_once __DIR__.'/app/Services/ArticleSpreadsheet.php';
require_once __DIR__.'/app/Services/SimpleXlsx.php';

require_login();
gt_erp_run_phase1_migrations($pdo);
$user=current_user($pdo)?:[];
if(!gt_erp_user_can($pdo,$user,'erp.view')){
    http_response_code(403);
    exit('Acesso reservado ao ERP.');
}

$path=SimpleXlsx::create(ArticleSpreadsheet::exportRows($pdo),'Artigos');
$filename='artigos_'.date('Y-m-d_His').'.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="'.$filename.'"');
header('Content-Length: '.filesize($path));
header('Cache-Control: private, no-store, max-age=0');
readfile($path);
@unlink($path);
