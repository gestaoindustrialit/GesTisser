<?php
declare(strict_types=1);
require_once __DIR__.'/../crm_migrations.php';

if (!extension_loaded('pdo_sqlite')) { echo "SKIP pdo_sqlite unavailable\n"; exit(0); }
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$pdo->exec('PRAGMA foreign_keys=ON');
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,name TEXT,is_admin INTEGER DEFAULT 0,is_active INTEGER DEFAULT 1,access_profile TEXT)');
$pdo->exec('CREATE TABLE erp_customers(id INTEGER PRIMARY KEY,name TEXT,is_active INTEGER DEFAULT 1)');
$pdo->exec('CREATE TABLE erp_permissions(id INTEGER PRIMARY KEY AUTOINCREMENT,code TEXT UNIQUE,label TEXT,description TEXT)');
$pdo->exec('CREATE TABLE erp_user_permissions(user_id INTEGER,permission_code TEXT,is_allowed INTEGER,PRIMARY KEY(user_id,permission_code))');
$pdo->exec('CREATE TABLE erp_role_permissions(profile TEXT,permission_code TEXT,PRIMARY KEY(profile,permission_code))');
$pdo->exec("INSERT INTO users(id,name,is_admin,is_active,access_profile) VALUES(1,'Ana',0,1,'Comercial'),(2,'Bruno',0,1,'Comercial'),(3,'Admin',1,1,'Admin')");
function erp_user_can(PDO $pdo,array $user,string $permission): bool {if((int)$user['is_admin']===1)return true;$s=$pdo->prepare('SELECT is_allowed FROM erp_user_permissions WHERE user_id=? AND permission_code=?');$s->execute([$user['id'],$permission]);return (bool)$s->fetchColumn();}
crm_run_migrations($pdo);require_once __DIR__.'/../app/Services/CrmService.php';
$pdo->exec("INSERT INTO erp_user_permissions(user_id,permission_code,is_allowed) VALUES(1,'crm.view',1)");
$ana=['id'=>1,'name'=>'Ana','is_admin'=>0,'access_profile'=>'Comercial'];$service=new CrmService($pdo,$ana);
$private=$service->saveLead(['company'=>'Lead privado']);
$pdo->exec("INSERT INTO crm_leads(company,created_by,assigned_to,visibility) VALUES('Partilhado',2,2,'shared'),('Outro privado',2,2,'private')");
$leads=$service->leads();if(count($leads)!==2)throw new RuntimeException('A política de visibilidade de leads falhou.');
$pdo->exec("INSERT INTO crm_opportunities(title,stage,estimated_value,probability,assigned_to,created_by) VALUES('Negócio','NEW',1000,10,1,1)");$oid=(int)$pdo->lastInsertId();$service->moveOpportunity($oid,'NEGOTIATION');
$row=$pdo->query('SELECT stage,probability FROM crm_opportunities WHERE id='.$oid)->fetch();if($row['stage']!=='NEGOTIATION'||(int)$row['probability']!==80)throw new RuntimeException('Movimento ou probabilidade do pipeline falhou.');
if((int)$pdo->query('SELECT COUNT(*) FROM crm_stage_history')->fetchColumn()!==1)throw new RuntimeException('Auditoria do pipeline não foi gravada.');
echo "OK CRM: migração, privacidade e auditoria de pipeline\n";
