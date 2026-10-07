<?php
/** Deliberately bypass legacy config.php: it performs migrations during every request. */
require_once __DIR__.'/bootstrap/config.php';
$dbPath=app_config('db_path');
if(!is_file($dbPath)) { http_response_code(503); exit('Base atual indisponível. Configurar a database.sqlite existente; este módulo não cria uma base nova.'); }
$pdo=new PDO('sqlite:'.$dbPath); // URI mode=ro is not supported by PDO SQLite in PHP 7.0.
// query_only is enforced before authentication, shared shell queries or validation.
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA query_only=ON');
// Existing authentication calls db(); return this read-only connection without security DDL.
function db() { return $GLOBALS['pdo']; }
require_once __DIR__.'/bootstrap/app.php';
require_admin();
// The shared shell asks this legacy helper; this endpoint admits admins only.
function has_shopfloor_only_navigation(array $user): bool { return false; }
require_once __DIR__.'/historical-import/Importer.php';
require_once __DIR__.'/historical-import/Workspace.php';
require_once __DIR__.'/historical-import/ArticleMatcher.php';
$user=current_user($pdo);$userId=(int)$user['id'];
// Bind scratch data to the authenticated user, including after a login switch.
if(($_SESSION['historical_user_id']??null)!==$userId) {
    unset($_SESSION['historical_workspace']);$_SESSION['historical_user_id']=$userId;
}
function historical_writable($path)
{
    $db=new PDO('sqlite:'.$path);$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
    $db->exec('PRAGMA foreign_keys=ON');$db->exec('PRAGMA busy_timeout=5000');return $db;
}
function historical_download($name,$contents,$mime)
{
    header('Content-Type: '.$mime);header('Content-Disposition: attachment; filename="'.$name.'"');header('Cache-Control: no-store');echo $contents;exit;
}
$error='';$success='';$config=HistoricalImporter::configuration();
try {
    if(($_GET['download']??'')==='templates') {
        $path=HistoricalSpreadsheet::templateZip();
        try { $contents=file_get_contents($path); } finally { unlink($path); }
        historical_download('templates_bobinas.zip',$contents,'application/zip');
    }
    if(($_GET['download']??'')==='article_template') {
        $path=HistoricalSpreadsheet::articleTemplate();try{$contents=file_get_contents($path);}finally{unlink($path);}
        historical_download('staging_artigos.xlsx',$contents,'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }
    if(($_GET['download']??'')==='article_report') {
        $report=HistoricalWorkspace::read('article_report');if(!$report) throw new RuntimeException('Analisar o staging dos artigos primeiro.');
        $path=HistoricalArticleMatcher::reportZip($report);try{$contents=file_get_contents($path);}finally{unlink($path);}
        historical_download('correspondencias_artigos.zip',$contents,'application/zip');
    }
    if(($_GET['download']??'')==='schema') historical_download('gestisser_schema.json',json_encode(['schema_hash'=>HistoricalValidator::schemaHash($pdo),'schema'=>HistoricalValidator::inspect($pdo)],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'application/json');
    if(($_GET['download']??'')==='log') historical_download('historical_import_log.json',json_encode(['report'=>HistoricalWorkspace::read('report'),'history'=>HistoricalWorkspace::read('history')],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'application/json');
    if(isset($_GET['run_log'])) {
        $s=$pdo->prepare('SELECT * FROM erp_legacy_import_runs WHERE id=?');$s->execute([(int)$_GET['run_log']]);$run=$s->fetch();
        if(!$run) throw new RuntimeException('Execução inexistente.');
        $s=$pdo->prepare('SELECT * FROM erp_legacy_import_logs WHERE import_run_id=? ORDER BY id');$s->execute([(int)$run['id']]);
        historical_download('historical_run_'.(int)$run['id'].'.json',json_encode(['run'=>$run,'lines'=>$s->fetchAll()],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'application/json');
    }
    if($_SERVER['REQUEST_METHOD']==='POST') {
        // Same CSRF token contract as the app, without writing csrf_failures during dry-run.
        if(empty($_POST['_token']) || empty($_SESSION['_csrf_token']) || !hash_equals($_SESSION['_csrf_token'],(string)$_POST['_token'])) { http_response_code(419);throw new RuntimeException('Pedido inválido (CSRF).'); }
        $action=$_POST['action']??'';
        if($action==='analyze_articles') {
            $parsed=HistoricalSpreadsheet::uploaded($_FILES['articles']??[]);
            if($parsed['entity']!=='article_matching') throw new RuntimeException('Usar o template staging_artigos.xlsx.');
            if(!$parsed['rows']) throw new RuntimeException('Staging vazio: extrair os artigos referenciados na fonte antiga antes de analisar.');
            $rows=array_map(function($row){return $row['data'];},$parsed['rows']);
            $articleReport=(new HistoricalArticleMatcher($pdo))->analyze($rows);
            $articleReport['schema_hash']=HistoricalValidator::schemaHash($pdo);$articleReport['file_hash']=hash_file('sha256',$_FILES['articles']['tmp_name']);$articleReport['analyzed_at']=date(DATE_ATOM);
            HistoricalWorkspace::write('article_report',$articleReport);$success='Correspondências exatas analisadas. Nenhum catálogo foi alterado.';
        } elseif($action==='upload') {
            $batch=HistoricalWorkspace::read('batch');$files=$_FILES['files']??[];
            if(!isset($files['name']) || !is_array($files['name']) || count($files['name'])>8) throw new RuntimeException('Selecionar até oito Excel.');
            $incoming=[];$bytes=0;
            foreach($files['name'] as $i=>$name) {
                $file=['name'=>$name,'error'=>$files['error'][$i],'tmp_name'=>$files['tmp_name'][$i],'size'=>$files['size'][$i]];
                $bytes+=(int)$file['size'];if($bytes>52428800) throw new RuntimeException('Limite total de 50 MB por upload.');
                $parsed=HistoricalSpreadsheet::uploaded($file);
                if(isset($incoming[$parsed['entity']])) throw new RuntimeException('Dois ficheiros da mesma entidade no upload.');
                if($parsed['entity']==='article_matching') throw new RuntimeException('Carregar o staging de artigos na área de correspondências.');
                $parsed['original_rows']=$parsed['rows'];
                $parsed['filename']=basename(str_replace('\\','/',$name));$parsed['file_hash']=hash_file('sha256',$file['tmp_name']);
                $incoming[$parsed['entity']]=$parsed;
            }
            if(!$incoming) throw new RuntimeException('Selecionar ficheiros.');
            $batch=array_replace($batch,$incoming);HistoricalWorkspace::write('batch',$batch);HistoricalWorkspace::resetReport();
            $success='Ficheiros recebidos. Upload não importa dados; validar o lote completo.';
        } elseif($action==='validate') {
            $batch=HistoricalWorkspace::read('batch');if(!$batch) throw new RuntimeException('Carregar os Excel primeiro.');
            $report=(new HistoricalValidator($pdo))->validate($batch);$report['validated_at']=date(DATE_ATOM);$report['batch_hash']=hash('sha256',json_encode($batch,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
            HistoricalWorkspace::write('report',$report);$history=HistoricalWorkspace::read('history');
            $summary=$report;foreach($summary['entities'] as &$entity) unset($entity['rows']);unset($entity);
            $history[]=$summary;HistoricalWorkspace::write('history',array_slice($history,-50));$success='Dry-run concluído. A base permaneceu apenas de leitura; relatório guardado na área privada da sessão.';
        } elseif($action==='resolve') {
            $batch=HistoricalWorkspace::read('batch');$entity=(string)($_POST['entity']??'');$line=(int)($_POST['line']??0);$field=(string)($_POST['field']??'');$value=trim((string)($_POST['value']??''));
            $allowed=['gestisser_customer_id','gestisser_supplier_id','gestisser_product_id','gestisser_raw_material_id','warehouse_id','location_id'];
            if(!in_array($field,$allowed,true) || !ctype_digit($value) || (int)$value<1 || !isset($batch[$entity])) throw new RuntimeException('Resolução inválida.');
            $found=false;foreach($batch[$entity]['rows'] as &$row) if($row['line']===$line && array_key_exists($field,$row['data'])) {$row['data'][$field]=$value;$found=true;}unset($row);
            if(!$found) throw new RuntimeException('Linha/campo inexistente.');
            HistoricalWorkspace::write('batch',$batch);HistoricalWorkspace::resetReport();$success='ID manual definido; é obrigatório repetir o dry-run.';
        } elseif($action==='clear') {HistoricalWorkspace::clear();$success='Lote e relatórios da sessão removidos.';}
        elseif($action==='setup') {
            if(app_config('env')!=='test') throw new RuntimeException('Preparação de schemas permitida apenas em gestisser-test nesta fase.');
            $articleReport=HistoricalWorkspace::read('article_report');
            if(!$articleReport || ($config['reviewed_article_report_hash']??'')!==($articleReport['file_hash']??'') || ($articleReport['schema_hash']??'')!==HistoricalValidator::schemaHash($pdo)) throw new RuntimeException('Validar primeiro o relatório real de correspondências e a sua aprovação técnica.');
            HistoricalImporter::setup(historical_writable($dbPath));$pdo=historical_writable($dbPath);$pdo->exec('PRAGMA query_only=ON');$success='Tabelas auxiliares preparadas. Nenhuma entidade histórica importada.';
        } elseif($action==='import') {
            if(app_config('env')!=='test') throw new RuntimeException('Execução de teste permitida apenas em gestisser-test; importação definitiva continua bloqueada.');
            $articleReport=HistoricalWorkspace::read('article_report');
            if(!$articleReport || ($config['reviewed_article_report_hash']??'')!==($articleReport['file_hash']??'') || ($articleReport['schema_hash']??'')!==HistoricalValidator::schemaHash($pdo)) throw new RuntimeException('Relatório real de correspondências ainda não aprovado para esta base.');
            if(($_POST['confirm']??'')!=='IMPORTAR BOBINAS') throw new RuntimeException('Escrever IMPORTAR BOBINAS para confirmar a execução real.');
            $batch=HistoricalWorkspace::read('batch');$report=HistoricalWorkspace::read('report');
            if(!$report || ($report['batch_hash']??'')!==hash('sha256',json_encode($batch,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))) throw new RuntimeException('Dry-run ausente ou desatualizado.');
            $result=HistoricalImporter::run(historical_writable($dbPath),$batch,$report,$config,$userId,__DIR__);
            $pdo=historical_writable($dbPath);$pdo->exec('PRAGMA query_only=ON');HistoricalWorkspace::resetReport();$success='Execução '.$result['id'].': '.$result['inserted'].' inseridos, '.$result['skipped'].' ignorados. Backup: '.$result['backup'];
        } else throw new RuntimeException('Ação desconhecida.');
    }
} catch(Throwable $e) { $error=$e->getMessage(); }
$batch=HistoricalWorkspace::read('batch');$report=HistoricalWorkspace::read('report');
$blockers=$report?HistoricalImporter::blockers($report,$config):['Efetuar o dry-run primeiro.'];
$destinations=HistoricalCompatibility::inspect($pdo);
$hasSchema=(bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE name='erp_legacy_import_runs'")->fetchColumn();
$runs=$hasSchema?$pdo->query('SELECT id,started_at,status,rows_inserted,rows_skipped,rows_errors FROM erp_legacy_import_runs ORDER BY id DESC LIMIT 30')->fetchAll():[];
$pageTitle='Importação histórica Bobinas';
// Shared shell queries are read-only. Disable the optional clock block on this page.
$navbarClockControl=[];
require __DIR__.'/partials/header.php';
?>
<div class="container-fluid py-4">
<h1 class="h3">Importação histórica Bobinas</h1>
<p class="text-secondary">Exportar → normalizar externamente → upload → dry-run → resolver → importar explicitamente.</p>
<div class="alert alert-warning">Importação real desativada por defeito. Não são criados clientes, fornecedores, artigos ou matérias-primas. Movimentos documentais não alteram saldos.</div>
<?php if($error): ?><div class="alert alert-danger" role="alert"><?= h($error) ?></div><?php endif; ?>
<?php if($success): ?><div class="alert alert-success" role="status"><?= h($success) ?></div><?php endif; ?>
<section class="card mb-3"><div class="card-body"><h2 class="h5">Analisar correspondências dos artigos antigos</h2>
<p>A correspondência usa os artigos atuais de erp_finished_products. A referência obrigatória em erp_products é uma questão técnica das OFs e não uma prova de falta de correspondência entre catálogos. Sem aproximações; referência, nome e cliente são comparados exatamente, preservando acentos e pontuação.</p>
<a class="btn btn-outline-primary mb-3" href="?download=article_template">Descarregar staging de artigos</a>
<form method="post" enctype="multipart/form-data"><?= csrf_input() ?><input type="hidden" name="action" value="analyze_articles"><label for="articles" class="form-label">Excel extraído dos artigos reais referenciados no sistema antigo</label><input id="articles" class="form-control mb-2" name="articles" type="file" accept=".xlsx" required><button class="btn btn-primary">Gerar relatório de correspondências</button></form>
<?php $articleReport=HistoricalWorkspace::read('article_report');if($articleReport): ?><p class="mt-3">Artigos antigos distintos: <?= (int)$articleReport['articles_total'] ?>; linhas: <?= (int)$articleReport['rows_total'] ?>; confirmados: <?= (int)$articleReport['confirmed'] ?>; exceções: <?= (int)$articleReport['exceptions'] ?>.</p><ul><?php foreach($articleReport['classifications'] as $classification=>$count): ?><li><?= h($classification) ?>: <?= (int)$count ?></li><?php endforeach; ?></ul><a class="btn btn-outline-primary" href="?download=article_report">Descarregar relatório e Excel de exceções</a><?php endif; ?>
<p class="small mt-3">Este relatório não importa dados, não aplica schemas e não confirma a origem MDB sem a respetiva extração. IDs sugeridos e casos contraditórios ficam pendentes. A evolução do esquema só deverá ser executada em gestisser-test depois de validar este relatório.</p>
</div></section>
<section class="card mb-3"><div class="card-body"><h2 class="h5">Compatibilidade dos destinos atuais</h2>
<table class="table"><thead><tr><th>Entidade</th><th>Tabela atual</th><th>Adaptador</th></tr></thead><tbody>
<?php foreach($destinations as $entity=>$destination): ?><tr><td><?= h(HistoricalSpreadsheet::contracts()[$entity]['label']) ?></td><td><?= h($destination['target']??'Destino por definir') ?></td><td><?= $destination['ready']?'Esquema compatível; exige aprovação':'Bloqueado' ?><?php foreach($destination['reasons'] as $reason): ?><div class="small text-secondary"><?= h($reason) ?></div><?php endforeach; ?></td></tr><?php endforeach; ?>
</tbody></table><p class="small">As OFs exigem também um artigo já existente em erp_products. Compatibilidade de colunas não autoriza uma importação.</p>
</div></section>
<section class="card mb-3"><div class="card-body"><h2 class="h5">1. Exportar templates</h2>
<a class="btn btn-primary" href="?download=templates">Descarregar templates de importação</a>
<a class="btn btn-outline-secondary" href="?download=schema">Descarregar esquema atual</a>
<p class="small mt-3 mb-0">ZIP com oito .xlsx, filtros e primeira linha congelada. Preencher importar=1 apenas nas linhas a importar. IDs/códigos como texto; datas AAAA-MM-DD; sem fórmulas. A leitura direta de .mdb não está ativada: exportar tabelas do Access conforme o guia.</p>
</div></section>
<section class="card mb-3"><div class="card-body"><h2 class="h5">2. Upload ficheiros normalizados</h2>
<form method="post" enctype="multipart/form-data"><?= csrf_input() ?><input type="hidden" name="action" value="upload">
<label class="form-label" for="files">Selecionar até oito Excel (.xlsx, 20 MB cada, 50 MB no conjunto)</label>
<input class="form-control mb-2" id="files" type="file" name="files[]" multiple accept=".xlsx" required><button class="btn btn-primary">Carregar Excel</button></form>
<ul class="mt-3"><?php foreach($batch as $entity=>$input): ?><li><?= h(HistoricalSpreadsheet::contracts()[$entity]['label']) ?>: <?= h($input['filename']) ?> — <?= count($input['rows']) ?> linhas</li><?php endforeach; ?></ul>
<p class="small">Um novo ficheiro substitui a mesma entidade no lote da sessão. Os restantes ficam disponíveis para validar as relações. Os ficheiros originais de upload não são conservados no servidor.</p>
<form method="post"><?= csrf_input() ?><button name="action" value="clear" class="btn btn-outline-secondary">Limpar lote da sessão</button></form>
</div></section>
<section class="card mb-3"><div class="card-body"><h2 class="h5">3. Validar / Dry-run</h2><form method="post"><?= csrf_input() ?><button class="btn btn-primary" name="action" value="validate" <?= !$batch?'disabled':'' ?>>Validar importação / Dry-run</button></form>
<p class="small mt-2">Nenhum INSERT, UPDATE ou criação de tabelas durante a validação. Relatórios de dry-run ficam em ficheiros privados da sessão, não em SQLite.</p>
<?php if($report): ?>
<div class="table-responsive"><table class="table mt-3"><thead><tr><th>Entidade</th><th>Total</th><th>Válidas</th><th>Ignoradas</th><th>Duplicadas / já importadas</th><th>Refs encontradas</th><th>Refs em falta</th><th>Linhas com erro</th><th>Avisos</th></tr></thead><tbody>
<?php foreach($report['entities'] as $entity=>$r): ?><tr><td><?= h(HistoricalSpreadsheet::contracts()[$entity]['label']) ?></td><?php foreach(['total','validas','ignoradas','duplicadas','referencias_encontradas','referencias_nao_encontradas','erros','avisos'] as $key): ?><td><?= (int)$r[$key] ?></td><?php endforeach; ?></tr><?php endforeach; ?>
</tbody></table></div><p class="small">Validado: <?= h($report['validated_at']??'') ?>. “Válida” indica dados e referências válidos; a aprovação do adaptador é verificada separadamente.</p>
<?php endif; ?></div></section>
<section class="card mb-3"><div class="card-body"><h2 class="h5">4. Ver problemas e resolver referências</h2>
<?php $shown=0;foreach($report['entities']??[] as $entity=>$r): foreach($r['rows'] as $row): if(!$row['errors'] && !$row['warnings']) continue; if($shown++>=200) continue; ?>
<details class="border rounded p-2 mb-2"><summary><?= h(HistoricalSpreadsheet::contracts()[$entity]['label']) ?> — linha <?= (int)$row['line'] ?> — <?= h($row['legacy_id']) ?> — <?= h($row['status']) ?></summary>
<ul><?php foreach(array_merge($row['errors'],$row['warnings']) as $message): ?><li><?= h($message) ?></li><?php endforeach; ?></ul></details>
<?php endforeach;endforeach; ?>
<?php if($shown>200): ?><p>Mostrados 200 problemas. Descarregar log para consultar todos.</p><?php endif; ?>
<p>Corrigir e reenviar o Excel, ou definir um ID existente e repetir o dry-run. IDs contraditórios com códigos encontrados serão rejeitados.</p>
<form method="post" class="row g-2"><?= csrf_input() ?><input type="hidden" name="action" value="resolve">
<div class="col-md-3"><label class="form-label">Entidade<select name="entity" class="form-select"><?php foreach($batch as $entity=>$input): ?><option value="<?= h($entity) ?>"><?= h(HistoricalSpreadsheet::contracts()[$entity]['label']) ?></option><?php endforeach; ?></select></label></div>
<div class="col-md-2"><label class="form-label">Linha Excel<input name="line" class="form-control" type="number" min="2" required></label></div>
<div class="col-md-3"><label class="form-label">Campo<select name="field" class="form-select"><?php foreach(['gestisser_customer_id','gestisser_supplier_id','gestisser_product_id','gestisser_raw_material_id','warehouse_id','location_id'] as $field): ?><option><?= h($field) ?></option><?php endforeach; ?></select></label></div>
<div class="col-md-2"><label class="form-label">ID existente<input name="value" class="form-control" type="number" min="1" required></label></div><div class="col-md-2 align-self-center"><button class="btn btn-outline-primary">Definir ID</button></div></form>
</div></section>
<section class="card mb-3"><div class="card-body"><h2 class="h5">5. Importar</h2>
<?php if(!$hasSchema): ?><form method="post" class="mb-3"><?= csrf_input() ?><button class="btn btn-outline-secondary" name="action" value="setup">Preparar apenas tabelas auxiliares</button></form><?php endif; ?>
<ul><?php foreach($blockers as $reason): ?><li><?= h($reason) ?></li><?php endforeach; ?></ul>
<form method="post"><?= csrf_input() ?><input type="hidden" name="action" value="import"><label class="form-label" for="confirm">Confirmação da execução real: escrever IMPORTAR BOBINAS</label><input class="form-control mb-2" id="confirm" name="confirm" autocomplete="off" <?= $blockers?'disabled':'' ?>><button class="btn btn-danger" <?= $blockers || !$hasSchema?'disabled':'' ?>>Importar lote validado</button></form>
<p class="small mt-2">Backup obrigatório, nova validação sob bloqueio de escrita e transação única. Qualquer erro provoca ROLLBACK. Sem atualizações automáticas.</p>
</div></section>
<section class="card"><div class="card-body"><h2 class="h5">6. Ver relatório / 7. Descarregar log</h2><a href="?download=log" class="btn btn-outline-primary">Descarregar log completo do dry-run</a>
<table class="table mt-3"><thead><tr><th>Execução</th><th>Data</th><th>Estado</th><th>Inseridos</th><th>Ignorados</th><th>Erros</th><th>Log</th></tr></thead><tbody><?php foreach($runs as $run): ?><tr><td><?= (int)$run['id'] ?></td><td><?= h($run['started_at']) ?></td><td><?= h($run['status']) ?></td><td><?= (int)$run['rows_inserted'] ?></td><td><?= (int)$run['rows_skipped'] ?></td><td><?= (int)$run['rows_errors'] ?></td><td><a href="?run_log=<?= (int)$run['id'] ?>">Descarregar</a></td></tr><?php endforeach; ?></tbody></table>
</div></section></div>
<?php require __DIR__.'/partials/footer.php'; ?>
