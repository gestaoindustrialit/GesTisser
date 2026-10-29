<?php
declare(strict_types=1);
require_once __DIR__.'/../erp_migrations.php';
require_once __DIR__.'/../assist_migrations.php';
require_once __DIR__.'/../app/Services/AssistService.php';
require_once __DIR__.'/../app/Services/AssistKnowledgeSpreadsheet.php';
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$pdo->exec('PRAGMA foreign_keys=ON');
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,is_admin INTEGER,is_active INTEGER,access_profile TEXT);
CREATE TABLE erp_permissions(code TEXT PRIMARY KEY,label TEXT,description TEXT);
CREATE TABLE erp_user_permissions(user_id INTEGER REFERENCES users(id),permission_code TEXT REFERENCES erp_permissions(code),is_allowed INTEGER,PRIMARY KEY(user_id,permission_code));
CREATE TABLE erp_role_permissions(profile TEXT,permission_code TEXT,PRIMARY KEY(profile,permission_code));
CREATE TABLE audit_logs(id INTEGER PRIMARY KEY,user_id INTEGER,action TEXT,details_json TEXT);
INSERT INTO users VALUES(1,1,1,"Admin"),(2,0,1,"Editor"),(3,0,1,"Operador");');
assist_run_migrations($pdo);
function spreadsheet_actor($id){global $pdo;$s=$pdo->prepare('SELECT * FROM users WHERE id=?');$s->execute([$id]);return new AssistService($pdo,$s->fetch());}
$admin=spreadsheet_actor(1);$editor=spreadsheet_actor(2);$operator=spreadsheet_actor(3);
$admin->configure(['pilot_users'=>'2,3','retention_days'=>180]);$admin->setPermission(2,'assist.edit','allow');$admin->setPermission(2,'assist.use','allow');$admin->setPermission(3,'assist.use','allow');
$passed=0;$temps=[];
function spreadsheet_check($ok,$label){global $passed;if(!$ok)throw new RuntimeException($label);echo 'OK '.$label."\n";$passed++;}
function spreadsheet_refuse(callable $f,$label){try{$f();}catch(InvalidArgumentException $e){spreadsheet_check(true,$label);return;}catch(RuntimeException $e){spreadsheet_check(true,$label);return;}throw new LogicException('Expected refusal: '.$label);}
function spreadsheet_csv($rows){global $temps;$p=tempnam(sys_get_temp_dir(),'assist_csv_');$temps[]=$p;$h=fopen($p,'wb');foreach($rows as $r)fputcsv($h,$r,';');fclose($h);return $p;}
function spreadsheet_xlsx($rows){global $temps;$p=SimpleXlsx::create($rows);$temps[]=$p;return $p;}
function spreadsheet_replace($path,$entry,$body){$z=new ZipArchive();$z->open($path);$z->addFromString($entry,$body);$z->close();}
function spreadsheet_xml($path){$z=new ZipArchive();$z->open($path);$s=$z->getFromName('xl/worksheets/sheet1.xml');$z->close();return $s;}
try {
    $cats=$editor->categories();$cid=(int)$cats[0]['id'];$category=$cats[0]['name'];
    $template=AssistKnowledgeSpreadsheet::template($cats);$temps[]=$template;$z=new ZipArchive();$z->open($template);
    spreadsheet_check($z->locateName('xl/worksheets/sheet3.xml')!==false,'template has category and instruction sheets');
    $sheet=$z->getFromName('xl/worksheets/sheet1.xml');$z->close();
    spreadsheet_check(strpos($sheet,'procedimento')!==false&&strpos($sheet,'titulo')!==false,'template exposes required headers');
    spreadsheet_check(strpos($sheet,'rascunho')===false,'template contains no sample technical article');
    spreadsheet_refuse(function()use($template,$cats){AssistKnowledgeSpreadsheet::read($template,'xlsx',$cats);},'blank template imports nothing');
    $headers=['titulo','categoria','procedimento','seguranca','sinonimos','idioma','revisao_ate'];
    $xlsx=spreadsheet_xlsx([$headers,['Artigo interno',$category,"1. Texto interno.\n2. Outra linha.",'Aviso interno.','expressão alternativa','pt','2030-10-01']]);
    $records=AssistKnowledgeSpreadsheet::read($xlsx,'xlsx',$cats);
    spreadsheet_check(count($records)===1&&$records[0]['category_id']===$cid,'Excel maps authorized category names');
    spreadsheet_check($records[0]['procedure']==="1. Texto interno.\n2. Outra linha.",'Excel preserves multiline procedure');
    $ids=$editor->importDrafts($records);$a=$editor->article($ids[0],true);
    spreadsheet_check($a['status']==='draft'&&!$a['approved_by']&&!$a['approved_at'],'Excel import creates only unapproved drafts');
    spreadsheet_check((int)$a['version']===1&&(int)$a['author_id']===2,'import uses existing version and author mechanism');
    spreadsheet_check($operator->retrieve('Artigo interno')['outcome']==='none','imported knowledge cannot answer before approval/publication');
    spreadsheet_check(count($editor->versions($ids[0]))===1,'import retains auditable initial version');
    $count=(int)$pdo->query('SELECT COUNT(*) FROM assist_articles')->fetchColumn();
    spreadsheet_refuse(function()use($editor,$records){$editor->importDrafts($records);},'repeat import does not overwrite existing article');
    spreadsheet_check((int)$pdo->query('SELECT COUNT(*) FROM assist_articles')->fetchColumn()===$count,'repeat import leaves database unchanged');
    spreadsheet_refuse(function()use($operator,$records){$operator->importDrafts($records);},'operator cannot import');
    $duplicate=$records[0];$duplicate['title']='Duplicado no lote';
    spreadsheet_refuse(function()use($editor,$duplicate){$editor->importDrafts([$duplicate,$duplicate]);},'duplicate batch rejected');
    $audit=(int)$pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();$good=$records[0];$good['title']='Novo válido';$bad=$good;$bad['title']=str_repeat('x',251);
    spreadsheet_refuse(function()use($editor,$good,$bad){$editor->importDrafts([$good,$bad]);},'later invalid row rejects entire batch');
    spreadsheet_check((int)$pdo->query('SELECT COUNT(*) FROM assist_articles')->fetchColumn()===$count,'atomic rollback removes earlier rows');
    spreadsheet_check((int)$pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn()===$audit,'atomic rollback removes earlier audit writes');
    $spoof=$records[0];$spoof['title']='Estado forjado';$spoof['id']=$ids[0];$spoof['version']=99;$spoof['status']='published';$spoof['approved_by']=1;
    $spoofId=$editor->importDrafts([$spoof])[0];
    spreadsheet_check($editor->article($spoofId,true)['status']==='draft'&&(int)$editor->article($ids[0],true)['version']===1,'forged IDs/approval/state cannot alter existing articles');
    $unknown=spreadsheet_csv([['titulo','categoria','procedimento'],['Título','Categoria inexistente','Texto']]);
    spreadsheet_refuse(function()use($unknown,$cats){AssistKnowledgeSpreadsheet::read($unknown,'csv',$cats);},'unknown category rejected without auto-creation');
    $secret=$admin->saveCategory(['name'=>'Reservada','restricted'=>1]);
    $file=spreadsheet_csv([['titulo','categoria','procedimento'],['Restrito','Reservada','Texto']]);
    spreadsheet_refuse(function()use($file,$editor){AssistKnowledgeSpreadsheet::read($file,'csv',$editor->categories());},'restricted category excluded from import lookup');
    $admin->setPermission(2,'assist.restricted','allow');$authorized=AssistKnowledgeSpreadsheet::read($file,'csv',$editor->categories());$admin->setPermission(2,'assist.restricted','deny');
    spreadsheet_refuse(function()use($editor,$authorized){$editor->importDrafts($authorized);},'category permissions rechecked on confirmation');
    $csv=spreadsheet_csv([['Título','Categoria','Procedimento','Idioma'],['UTF-8 português',$category,'Texto íntegro','']]);$rows=AssistKnowledgeSpreadsheet::read($csv,'csv',$cats);
    spreadsheet_check($rows[0]['language']==='pt'&&$rows[0]['procedure']==='Texto íntegro','CSV UTF-8 accents and default Portuguese');
    $unknownColumn=spreadsheet_csv([['titulo','categoria','procedimento','status'],['Título',$category,'Texto','published']]);
    spreadsheet_refuse(function()use($unknownColumn,$cats){AssistKnowledgeSpreadsheet::read($unknownColumn,'csv',$cats);},'status/unknown columns refused');
    foreach(['','Texto'] as $value){$invalid=spreadsheet_csv([['titulo','categoria','procedimento','idioma'],['Título',$category,$value,'de']]);spreadsheet_refuse(function()use($invalid,$cats){AssistKnowledgeSpreadsheet::read($invalid,'csv',$cats);},'empty required value or invalid language refused');}
    $dateFile=spreadsheet_xlsx([$headers,['Data Excel',$category,'Texto','','','pt','46023']]);$dated=AssistKnowledgeSpreadsheet::read($dateFile,'xlsx',$cats);
    spreadsheet_check(preg_match('/^2026-/', $dated[0]['expires_on'])===1,'Excel serial date converted');
    $z=new ZipArchive();$z->open($dateFile);$workbook=$z->getFromName('xl/workbook.xml');$z->close();spreadsheet_replace($dateFile,'xl/workbook.xml',str_replace('<sheets>','<workbookPr date1904="1"/><sheets>',$workbook));
    $dated1904=AssistKnowledgeSpreadsheet::read($dateFile,'xlsx',$cats);
    spreadsheet_check((new DateTimeImmutable($dated[0]['expires_on']))->diff(new DateTimeImmutable($dated1904[0]['expires_on']))->days===1462,'Excel 1904 date system respected');
    $external=spreadsheet_xlsx([['titulo','categoria','procedimento'],['Ligação',$category,'Texto']]);
    spreadsheet_replace($external,'xl/_rels/workbook.xml.rels','<?xml version="1.0"?><Relationships><Relationship Id="rId1" Target="https://example.invalid/worksheet.xml" TargetMode="External"/></Relationships>');
    spreadsheet_refuse(function()use($external,$cats){AssistKnowledgeSpreadsheet::read($external,'xlsx',$cats);},'external spreadsheet relationships refused without network access');
    $tooMany=[['titulo','categoria','procedimento']];for($i=0;$i<201;$i++)$tooMany[]=['Artigo '.$i,$category,'Texto'];$limit=spreadsheet_csv($tooMany);
    spreadsheet_refuse(function()use($limit,$cats){AssistKnowledgeSpreadsheet::read($limit,'csv',$cats);},'200-row import limit');
    $formula=spreadsheet_xlsx([['titulo','categoria','procedimento'],['Fórmula',$category,'Texto']]);
    spreadsheet_replace($formula,'xl/worksheets/sheet1.xml',str_replace('</worksheet>','<f>WEBSERVICE("https://example.invalid")</f></worksheet>',spreadsheet_xml($formula)));
    spreadsheet_refuse(function()use($formula,$cats){AssistKnowledgeSpreadsheet::read($formula,'xlsx',$cats);},'Excel formulas never evaluated or cached values accepted');
    $dtd=spreadsheet_xlsx([['titulo','categoria','procedimento']]);spreadsheet_replace($dtd,'xl/worksheets/sheet1.xml','<!DOCTYPE worksheet [<!ENTITY unsafe "value">]>'.spreadsheet_xml($dtd));
    spreadsheet_refuse(function()use($dtd,$cats){AssistKnowledgeSpreadsheet::read($dtd,'xlsx',$cats);},'XML entities rejected');
    $hugeReference=spreadsheet_xlsx([['titulo','categoria','procedimento']]);spreadsheet_replace($hugeReference,'xl/worksheets/sheet1.xml',str_replace('r="A1"','r="AAAAAA1"',spreadsheet_xml($hugeReference)));
    spreadsheet_refuse(function()use($hugeReference,$cats){AssistKnowledgeSpreadsheet::read($hugeReference,'xlsx',$cats);},'oversized column reference rejected before shared reader allocation');
    $inflated=spreadsheet_xlsx([['titulo','categoria','procedimento']]);spreadsheet_replace($inflated,'xl/worksheets/sheet1.xml',str_repeat(' ',4194305));
    spreadsheet_refuse(function()use($inflated,$cats){AssistKnowledgeSpreadsheet::read($inflated,'xlsx',$cats);},'ZIP uncompressed part limit');
    spreadsheet_refuse(function()use($csv,$cats){AssistKnowledgeSpreadsheet::read($csv,'xlsm',$cats);},'macro workbook extension refused');
    spreadsheet_refuse(function()use($csv,$cats){AssistKnowledgeSpreadsheet::read($csv,'xlsx',$cats);},'renamed CSV cannot masquerade as Excel');
    spreadsheet_check(!$pdo->query('PRAGMA foreign_key_check')->fetch(),'import preserves foreign keys');
    spreadsheet_check((int)$pdo->query('SELECT COUNT(*) FROM audit_logs WHERE action="assist.import"')->fetchColumn()===2,'successful batches audited');
    echo 'OK Assist spreadsheet: '.$passed." assertions.\n";
} finally {foreach($temps as $tmp)@unlink($tmp);}
