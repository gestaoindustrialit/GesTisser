<?php
declare(strict_types=1);
require_once __DIR__.'/../erp_migrations.php';
require_once __DIR__.'/../assist_migrations.php';
require_once __DIR__.'/../app/Services/AssistService.php';
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$pdo->exec('PRAGMA foreign_keys=ON');
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,is_admin INTEGER,is_active INTEGER,access_profile TEXT,email TEXT);
CREATE TABLE erp_permissions(code TEXT PRIMARY KEY,label TEXT,description TEXT);
CREATE TABLE erp_user_permissions(user_id INTEGER REFERENCES users(id),permission_code TEXT REFERENCES erp_permissions(code),is_allowed INTEGER,PRIMARY KEY(user_id,permission_code));
CREATE TABLE erp_role_permissions(profile TEXT,permission_code TEXT,PRIMARY KEY(profile,permission_code));
CREATE TABLE audit_logs(id INTEGER PRIMARY KEY,user_id INTEGER,action TEXT,details_json TEXT);
INSERT INTO users VALUES(1,1,1,"Admin","admin@example.invalid"),(2,0,1,"Utilizador","one@example.invalid"),(3,0,1,"Utilizador","two@example.invalid"),(4,0,1,"Shopfloor","shopfloor@tisser.pt"),(5,1,1,"Admin","other@example.invalid");');
assist_run_migrations($pdo);
function pin_actor($id,$gateway=0){global $pdo;$s=$pdo->prepare('SELECT * FROM users WHERE id=?');$s->execute([$id]);return new AssistService($pdo,$s->fetch(),$gateway);}
$n=0;function pin_check($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);$n++;echo 'OK '.$label."\n";}
function pin_refuse(callable $f,$label){try{$f();}catch(RuntimeException $e){pin_check(true,$label);return;}catch(InvalidArgumentException $e){pin_check(true,$label);return;}throw new LogicException('Expected refusal: '.$label);}
$admin=pin_actor(1);$one=pin_actor(2,4);$two=pin_actor(3,4);
pin_check(!$one->enabled()&&!$one->can('assist.use'),'valid gateway context does not activate unconfigured users');
$admin->enableShopfloorGateway();
pin_check($one->enabled()&&$one->can('assist.use'),'shared entry grants consultation after explicit administrator activation');
pin_check($two->enabled()&&$two->can('assist.use'),'second PIN employee needs no individual grant');
pin_check($admin->setting('enabled')==='0'&&$admin->setting('pilot_users')==='4','shared activation preserves global feature flag');
$admin->enableShopfloorGateway();pin_check($admin->setting('pilot_users')==='4','activation idempotent');
pin_check((int)$pdo->query('SELECT COUNT(*) FROM erp_user_permissions WHERE user_id IN (2,3)')->fetchColumn()===0,'no permissions copied to employee rows');
foreach(['edit','validate','publish','admin','restricted'] as $permission){$admin->setPermission(4,'assist.'.$permission,'allow');pin_check(!$one->can('assist.'.$permission),'shared entry does not delegate '.$permission);}
$admin->setPermission(2,'assist.use','deny');pin_check(!$one->can('assist.use')&&$two->can('assist.use'),'personal explicit denial overrides shared grant');$admin->setPermission(2,'assist.use','inherit');
$admin->setPermission(4,'assist.use','deny');pin_check(!$two->can('assist.use'),'gateway permission revocation applies immediately');$admin->setPermission(4,'assist.use','allow');
$pdo->exec('UPDATE users SET is_active=0 WHERE id=4');pin_check(!$one->enabled()&&!$one->can('assist.use'),'inactive gateway does not delegate');$pdo->exec('UPDATE users SET is_active=1 WHERE id=4');
$admin->configure(['retention_days'=>180,'pilot_users'=>'']);pin_check(!$one->enabled(),'removing pilot gateway withdraws activation');$admin->enableShopfloorGateway();
pin_check(!pin_actor(2)->can('assist.use')&&!pin_actor(2)->enabled(),'no inheritance without PIN gateway context');
pin_check(!pin_actor(2,5)->can('assist.use'),'arbitrary admin account cannot be a gateway');
$_SESSION=['user_id'=>2,'login_mode'=>'password','shopfloor_gateway_user_id'=>4];$row=$pdo->query('SELECT * FROM users WHERE id=2')->fetch();pin_check(!AssistService::fromSession($pdo,$row)->can('assist.use'),'password session cannot reuse gateway marker');
$_SESSION['login_mode']='pin';pin_check(AssistService::fromSession($pdo,$row)->can('assist.use'),'PIN session with matched employee uses trusted marker');
$_SESSION['user_id']=3;pin_check(!AssistService::fromSession($pdo,$row)->can('assist.use'),'gateway marker cannot be applied to a different actor');
$pdo->exec('UPDATE users SET is_admin=1 WHERE id=4');$admin->setPermission(4,'assist.use','deny');pin_check(!$one->can('assist.use'),'gateway explicit denial blocks delegation even for admin gateway');$admin->setPermission(4,'assist.use','allow');
$category=(int)$pdo->query('SELECT id FROM assist_categories LIMIT 1')->fetchColumn();$id=$admin->saveArticle(['category_id'=>$category,'title'=>'Texto aprovado','procedure'=>'Procedimento interno.']);$admin->transition($id,1,'submit');$admin->transition($id,1,'approve');$admin->transition($id,1,'publish');
$a=$one->ask('Texto aprovado','pt');pin_check($a['answer']['outcome']==='answer','PIN employee can retrieve published unrestricted knowledge');
pin_check((int)$pdo->query('SELECT user_id FROM assist_messages WHERE id='.(int)$a['id'])->fetchColumn()===2,'question belongs to actual employee, not gateway');
pin_check(count($two->history())===0,'gateway does not combine employees histories');
pin_refuse(function()use($two,$a){$two->feedback($a['id'],1);},'feedback remains private to employee');
$secretCategory=$admin->saveCategory(['name'=>'Reservado','restricted'=>1]);$secret=$admin->saveArticle(['category_id'=>$secretCategory,'title'=>'Privado','procedure'=>'Texto reservado.']);$admin->transition($secret,1,'submit');$admin->transition($secret,1,'approve');$admin->transition($secret,1,'publish');
pin_check($one->retrieve('Privado')['outcome']==='none','gateway restricted grant does not expose restricted sources');
pin_refuse(function()use($one,$secret){$one->article($secret);},'restricted direct source remains denied');
pin_refuse(function()use($one){$one->enableShopfloorGateway();},'operator cannot enable shared entry');
pin_check(!$pdo->query('PRAGMA foreign_key_check')->fetch(),'shared access preserves foreign keys');
echo 'OK PIN gateway: '.$n." assertions.\n";
