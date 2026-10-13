<?php
require_once __DIR__.'/historical-import/legacy-of-bootstrap.php';
require_admin();require_once __DIR__.'/historical-import/Workspace.php';
// Separate this module's scratch space from the older importer's reports.
if(($_SESSION['legacy_of_workspace_user']??0)!==(int)$user['id']){$_SESSION['legacy_of_workspace_user']=(int)$user['id'];$_SESSION['legacy_of_workspace_token']=bin2hex(random_bytes(24));}
$oldWorkspace=$_SESSION['historical_workspace']??null;
$_SESSION['historical_workspace']=$_SESSION['legacy_of_workspace_token'];
register_shutdown_function(function()use($oldWorkspace){if($oldWorkspace===null)unset($_SESSION['historical_workspace']);else $_SESSION['historical_workspace']=$oldWorkspace;});
$service=new LegacyOfHistory($pdo);$error='';$message='';
if(lo_text($_GET,'download')==='templates') {
    $zipPath=tempnam(sys_get_temp_dir(),'legacy-of-templates-');$zip=new ZipArchive();
    if($zip->open($zipPath,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new RuntimeException('Falha ao criar templates.');
    try {
        foreach(['ofs','of_operacoes'] as $entity){$contract=HistoricalSpreadsheet::contracts()[$entity];$file=HistoricalSpreadsheet::workbook([$contract['columns']],$contract['label']);try{$zip->addFromString($contract['file'],file_get_contents($file));}finally{unlink($file);}}
        $zip->addFromString('LEIA-ME.txt',"IDs/códigos como texto. importar=1 seleciona a linha. Sem fórmulas. Datas AAAA-MM-DD. Decimais sem separadores de milhares. Custos e unidades ausentes ficam desconhecidos. Campos opcionais documentados em docs/legacy-of-history.md.
");
        $zip->close();header('Content-Type: application/zip');header('Content-Disposition: attachment; filename=templates-of-bobinas.zip');header('Cache-Control: no-store');readfile($zipPath);
    }finally{if(is_file($zipPath))unlink($zipPath);}exit;
}
if(lo_text($_GET,'download')==='report') {
    header('Content-Type: application/json');header('Content-Disposition: attachment; filename="bobinas-of-dry-run.json"');header('Cache-Control: no-store');echo json_encode(HistoricalWorkspace::read('report'),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);exit;
}
try {
 if(($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
    lo_csrf();$action=lo_text($_POST,'action');
    if($action==='upload') {
        $files=$_FILES['files']??[];$incoming=[];$bytes=0;
        if(!is_array($files['name']??null)||count($files['name'])>2)throw new RuntimeException('Selecionar até dois Excel.');
        foreach($files['name'] as $i=>$name){
            $f=['name'=>$name,'tmp_name'=>$files['tmp_name'][$i],'size'=>$files['size'][$i],'error'=>$files['error'][$i]];$bytes+=(int)$f['size'];if($bytes>41943040)throw new RuntimeException('Limite total de 40 MB.');
            $parsed=HistoricalSpreadsheet::uploaded($f);
            if(!in_array($parsed['entity'],['ofs','of_operacoes'],true)||isset($incoming[$parsed['entity']]))throw new RuntimeException('Aceites apenas um 03_ofs.xlsx e um 04_of_operacoes.xlsx.');
            $parsed['filename']=basename(str_replace('\\','/',$name));$parsed['file_hash']=hash_file('sha256',$f['tmp_name']);$incoming[$parsed['entity']]=$parsed;
        }
        if(!$incoming)throw new RuntimeException('Selecionar Excel.');
        $batch=array_replace(HistoricalWorkspace::read('batch'),$incoming);HistoricalWorkspace::write('batch',$batch);HistoricalWorkspace::resetReport();$message='Upload recebido. Reveja a pré-visualização e execute o dry-run.';
    }elseif($action==='validate') {
        $batch=HistoricalWorkspace::read('batch');if(!$batch)throw new RuntimeException('Carregar o lote primeiro.');
        HistoricalWorkspace::write('report',$service->validate($batch));$message='Dry-run concluído sem escritas na base.';
    }elseif($action==='approve_reference') {
        $batch=HistoricalWorkspace::read('batch');$kind=lo_text($_POST,'kind');$entity=lo_text($_POST,'entity');$line=(int)lo_text($_POST,'line');$id=lo_text($_POST,'target');$evidence=lo_text($_POST,'evidence');
        if($entity!=='ofs'||!in_array($kind,['customer','product'],true)||!ctype_digit($id)||(int)$id<1||$evidence==='')throw new RuntimeException('Selecionar cliente/artigo, ID e evidência documental.');
        $found=false;foreach($batch['ofs']['rows']??[] as $index=>$row)if($row['line']===$line){$batch['ofs']['rows'][$index]['approvals'][$kind]=['id'=>$id,'evidence'=>$evidence,'user_id'=>(int)$user['id']];$found=true;}
        if(!$found)throw new RuntimeException('Linha não encontrada.');
        HistoricalWorkspace::write('batch',$batch);HistoricalWorkspace::resetReport();$message='Evidência guardada no lote; repetir dry-run. Os dados de origem foram preservados.';
    }elseif($action==='setup') {
        if(lo_text($_POST,'confirmation')!=='PREPARAR HISTORICO')throw new RuntimeException('Escrever PREPARAR HISTORICO.');
        $writer=new LegacyOfHistory(lo_write_connection());$writer->setup(app_config('env'),(int)$user['id'],lo_backup_dir());
        // PHP 7.0 SQLite cannot reliably reuse prepared statements after DDL on another connection.
        $pdo=lo_write_connection();$pdo->exec('PRAGMA query_only=ON');$service=new LegacyOfHistory($pdo);HistoricalWorkspace::resetReport();$message='Estrutura histórica preparada com backup verificado. Nenhuma OF importada.';
    }elseif($action==='import') {
        $writer=new LegacyOfHistory(lo_write_connection());$result=$writer->import(HistoricalWorkspace::read('batch'),HistoricalWorkspace::read('report'),app_config('env'),(int)$user['id'],lo_text($_POST,'confirmation'),lo_backup_dir());
        HistoricalWorkspace::resetReport();$message='Lote '.$result['batch_id'].': '.$result['report']['inserted'].' inseridos; '.$result['report']['skipped'].' ignorados; '.$result['report']['pending'].' OFs com referências pendentes.';
    }elseif($action==='clear'){HistoricalWorkspace::clear();$message='Lote temporário eliminado.';}
    else throw new RuntimeException('Ação inválida.');
 }
}catch(Throwable $e){$error=$e->getMessage();if(http_response_code()<400)http_response_code(422);}
$batch=HistoricalWorkspace::read('batch');$report=HistoricalWorkspace::read('report');$pageTitle='Bobinas · Importação histórica de OFs';require __DIR__.'/partials/header.php';
?>
<div class="container py-4"><h1 class="h3">Importação histórica de OFs · Bobinas</h1><p>Upload → pré-visualização → validação e referências → dry-run → confirmação administrativa → auditoria.</p><p><a href="?download=templates">Descarregar templates OFs / operações</a> · <a href="legacy_of_history.php">Consultar OFs atuais e históricas</a> · <a href="legacy_of_history.php?pending=1&amp;origin=Bobinas">Resolver referências pendentes</a></p>
<?php if($error):?><div class="alert alert-danger"><?= h($error) ?></div><?php endif;?><?php if($message):?><div class="alert alert-success"><?= h($message) ?></div><?php endif;?>
<form method="post" enctype="multipart/form-data" class="border rounded p-3 mb-3"><input type="hidden" name="_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="upload"><label class="form-label">03_ofs.xlsx e 04_of_operacoes.xlsx</label><input class="form-control" type="file" name="files[]" accept=".xlsx" multiple required><button class="btn btn-primary mt-2">Carregar Excel</button></form>
<?php foreach($batch as $entity=>$file):?><h2 class="h5"><?= h($file['filename']) ?> · <?= count($file['rows']) ?> linhas</h2><div class="table-responsive"><table class="table table-sm"><thead><tr><th>Linha</th><?php foreach(array_keys($file['rows'][0]['data']??[]) as $k):?><th><?= h($k) ?></th><?php endforeach;?></tr></thead><tbody><?php foreach(array_slice($file['rows'],0,10) as $row):?><tr><td><?= (int)$row['line'] ?></td><?php foreach($row['data'] as $v):?><td><?= h($v) ?></td><?php endforeach;?></tr><?php endforeach;?></tbody></table></div><?php endforeach;?>
<form method="post" class="my-3"><input type="hidden" name="_token" value="<?= h(csrf_token()) ?>"><button class="btn btn-primary" name="action" value="validate">Validar / Dry-run</button> <button class="btn btn-outline-secondary" name="action" value="clear">Limpar lote</button></form>
<?php if($report):?><p class="alert alert-info"><?= (int)$report['inserted'] ?> inserções previstas · <?= (int)$report['skipped'] ?> ignorados/duplicados · <?= (int)$report['pending'] ?> OFs pendentes · <?= (int)$report['errors'] ?> linhas com erros. <a href="?download=report">Descarregar relatório completo</a></p>
<?php $shown=0;foreach($report['entities'] as $entity=>$rows):foreach($rows as $row):if($row['status']==='ignored'||$shown++>=200)continue;?><details class="border rounded p-2 mb-2"><summary><?= h($entity.' · linha '.$row['line'].' · '.$row['legacy_id'].' · '.$row['status']) ?></summary><?php foreach(array_merge($row['errors'],$row['warnings']) as $text):?><p><?= h($text) ?></p><?php endforeach;?><?php if($entity==='ofs'&&(!empty($row['warnings'])||!empty($row['errors']))):?><form method="post"><input type="hidden" name="_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="approve_reference"><input type="hidden" name="entity" value="ofs"><input type="hidden" name="line" value="<?= (int)$row['line'] ?>"><label>Referência <select name="kind"><option value="customer">Cliente</option><option value="product">Artigo</option></select></label> <label>ID atual <input name="target" inputmode="numeric" required></label> <label>Evidência documental <input name="evidence" maxlength="2000" required></label> <button class="btn btn-sm btn-outline-secondary">Confirmar correspondência do lote</button></form><?php endif;?></details><?php endforeach;endforeach;?><?php endif;?>
<?php if(!$service->ready()):?><form method="post" class="border rounded p-3"><input type="hidden" name="_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="setup"><label>Escrever PREPARAR HISTORICO <input name="confirmation" required></label> <button class="btn btn-outline-primary">Preparar estrutura com backup</button></form><?php endif;?>
<?php if($service->ready()&&$report&&$report['errors']===0):?><form method="post" class="border rounded p-3"><input type="hidden" name="_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="import"><p>Importar apenas o pequeno lote controlado revisto. Será criado um backup verificado.</p><label>Escrever IMPORTAR LOTE HISTORICO <input name="confirmation" required></label> <button class="btn btn-primary">Importar lote validado</button></form><?php endif;?>
<?php if($service->ready()):?><h2 class="h5 mt-4">Auditoria de lotes</h2><div class="table-responsive"><table class="table"><thead><tr><th>Lote</th><th>Data</th><th>Estado</th><th>Relatório</th></tr></thead><tbody><?php foreach($pdo->query('SELECT id,imported_at,status,report_json FROM erp_legacy_of_batches ORDER BY id DESC LIMIT 30') as $r):?><tr><td><?= (int)$r['id'] ?></td><td><?= h($r['imported_at']) ?></td><td><?= h($r['status']) ?></td><td><details><summary>Consultar</summary><pre><?= h(json_encode(json_decode($r['report_json'],true),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)) ?></pre></details></td></tr><?php endforeach;?></tbody></table></div><?php endif;?>
</div><?php require __DIR__.'/partials/footer.php'; ?>
