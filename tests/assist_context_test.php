<?php
declare(strict_types=1);
require_once __DIR__.'/../erp_migrations.php';
require_once __DIR__.'/../assist_migrations.php';
require_once __DIR__.'/../app/Services/AssistService.php';
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$pdo->exec('PRAGMA foreign_keys=ON');
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,is_admin INTEGER,is_active INTEGER,access_profile TEXT,email TEXT);
CREATE TABLE erp_permissions(code TEXT PRIMARY KEY,label TEXT,description TEXT);
CREATE TABLE erp_user_permissions(user_id INTEGER,permission_code TEXT,is_allowed INTEGER,PRIMARY KEY(user_id,permission_code));
CREATE TABLE erp_role_permissions(profile TEXT,permission_code TEXT,PRIMARY KEY(profile,permission_code));
CREATE TABLE audit_logs(id INTEGER PRIMARY KEY,user_id INTEGER,action TEXT,details_json TEXT);
CREATE TABLE erp_machines(id INTEGER PRIMARY KEY,code TEXT,name TEXT);
CREATE TABLE erp_operations(id INTEGER PRIMARY KEY,code TEXT,name TEXT);
INSERT INTO users VALUES(1,1,1,"Admin","admin@example.invalid"),(2,0,1,"Utilizador","operator@example.invalid"),(3,0,1,"Shopfloor","shopfloor@tisser.pt");
INSERT INTO erp_machines VALUES(7,"M-01","Impressora Flexográfica"),(8,"M-02","Outra máquina");
INSERT INTO erp_operations VALUES(11,"OP-01","Inspeção"),(12,"OP-02","Outra operação");');
assist_run_migrations($pdo);
function context_actor($id,$gateway=0){global $pdo;$s=$pdo->prepare('SELECT * FROM users WHERE id=?');$s->execute([$id]);return new AssistService($pdo,$s->fetch(),$gateway);}
$n=0;function context_check($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);$n++;echo 'OK '.$label."\n";}
$admin=context_actor(1);$operator=context_actor(2,3);$admin->enableShopfloorGateway();$category=(int)$pdo->query('SELECT id FROM assist_categories LIMIT 1')->fetchColumn();
$step='Consultar a instrução interna aprovada.';
foreach(['M-01','Impressora Flexográfica','M-01 · Impressora Flexográfica','7'] as $equipment){
    $id=$admin->saveArticle(['category_id'=>$category,'title'=>'Inspeção documentada','procedure'=>$step,'equipment'=>$equipment]);$admin->transition($id,1,'submit');$admin->transition($id,1,'approve');$admin->transition($id,1,'publish');
    context_check($operator->retrieve('Inspeção documentada')['outcome']==='answer','published source accessible without machine context: '.$equipment);
    $answer=$operator->retrieve('Inspeção documentada','pt',['equipment'=>'7']);
    context_check($answer['outcome']==='answer'&&$answer['text']===$step,'tablet machine ID matches approved equipment: '.$equipment);
    context_check($answer['sources'][0]['equipment']===$equipment,'source equipment remains approved original label');
    context_check(count($operator->suggestions('pt',['equipment'=>'7']))===1,'suggestions match the same current machine: '.$equipment);
    context_check(count($operator->suggestions('pt',['equipment'=>'8']))===0,'suggestions exclude other machine scopes: '.$equipment);
    context_check($operator->retrieve('Inspeção documentada','pt',['equipment'=>'8'])['outcome']==='none','other machine remains excluded: '.$equipment);
    context_check($operator->retrieve('Inspeção documentada','pt',['equipment'=>'999'])['outcome']==='none','unknown machine remains excluded: '.$equipment);
    $admin->transition($id,1,'archive');
}
$id=$admin->saveArticle(['category_id'=>$category,'title'=>'Controlo aprovado','procedure'=>$step,'equipment'=>'M-01','operation'=>'Inspeção']);$admin->transition($id,1,'submit');$admin->transition($id,1,'approve');$admin->transition($id,1,'publish');
context_check($operator->retrieve('Controlo aprovado','pt',['equipment'=>'7','operation'=>'11'])['outcome']==='answer','machine and operation IDs resolve their ERP labels');
context_check($operator->retrieve('Controlo aprovado','pt',['equipment'=>'7','operation'=>'12'])['outcome']==='none','wrong operation remains excluded');
$secret=$admin->saveCategory(['name'=>'Reservado','restricted'=>1]);$secretId=$admin->saveArticle(['category_id'=>$secret,'title'=>'Controlo reservado','procedure'=>'Texto reservado.','equipment'=>'M-01']);$admin->transition($secretId,1,'submit');$admin->transition($secretId,1,'approve');$admin->transition($secretId,1,'publish');
context_check($operator->retrieve('Controlo reservado','pt',['equipment'=>'7'])['outcome']==='none','ERP name resolution never grants restricted access');
$draft=$admin->saveArticle(['category_id'=>$category,'title'=>'Instrução rascunho','procedure'=>'Texto não aprovado.','equipment'=>'M-01']);
context_check($operator->retrieve('Instrução rascunho','pt',['equipment'=>'7'])['outcome']==='none','machine match never exposes an unapproved source');
context_check($operator->retrieve('Qual o valor?','pt',['equipment'=>'7'])['outcome']==='none','unsupported value still abstains');
$baseline=$operator->retrieve('Controlo aprovado','pt',['equipment'=>'7','operation'=>'11']);
if($pdo->query("SELECT 1 FROM sqlite_master WHERE name='assist_search'")->fetchColumn())$pdo->exec('DROP TABLE assist_search');
context_check($operator->retrieve('Controlo aprovado','pt',['equipment'=>'7','operation'=>'11'])===$baseline,'context search parity without FTS');
$pdo->exec('DROP TABLE erp_machines');
context_check($operator->retrieve('Controlo aprovado','pt',['equipment'=>'7'])['outcome']==='none','missing ERP registry preserves conservative context matching');
context_check($operator->retrieve('Controlo aprovado','pt',['equipment'=>'M-01'])['outcome']==='answer','literal equipment matching remains available without ERP registry');
context_check($operator->retrieve('Controlo aprovado','pt',['equipment'=>'7 UNION SELECT secret'])['outcome']==='none','context does not execute SQL or guess an entity');
echo 'OK Assist context: '.$n." assertions.\n";
