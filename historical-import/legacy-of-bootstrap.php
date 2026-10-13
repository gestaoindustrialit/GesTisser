<?php
/** Standalone read-only entry avoids config.php migrations and status changes. */
require_once dirname(__DIR__).'/bootstrap/config.php';
if(app_config('env')!=='gestisser-dev'){http_response_code(403);exit('Módulo histórico disponível exclusivamente em gestisser-dev.');}
$path=app_config('db_path');
if(!is_file($path)){http_response_code(503);exit('Configurar GESTISSER_DB_PATH para a base existente de gestisser-dev.');}
$pdo=new PDO('sqlite:'.$path);$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$pdo->exec('PRAGMA query_only=ON');
function db(){return $GLOBALS['pdo'];}
function has_shopfloor_only_navigation(array $user): bool {return (int)($user['is_admin']??0)!==1&&((int)($user['pin_only_login']??0)===1||($user['access_profile']??'')==='Utilizador');}
require_once dirname(__DIR__).'/bootstrap/app.php';
require_once dirname(__DIR__).'/erp_migrations.php';
require_once __DIR__.'/LegacyOfHistory.php';
require_login();$user=current_user($pdo);
function lo_write_connection(){
    $db=new PDO('sqlite:'.app_config('db_path'));$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$db->exec('PRAGMA foreign_keys=ON');$db->exec('PRAGMA busy_timeout=5000');return $db;
}
function lo_backup_dir(){return app_config('paths.backups').'/legacy-of';}
function lo_text(array $a,$k,$default=''){return is_scalar($a[$k]??null)?trim((string)$a[$k]):$default;}
function lo_csrf(){if(!is_string($_POST['_token']??null)||!hash_equals(csrf_token(),$_POST['_token'])){http_response_code(419);throw new RuntimeException('Pedido inválido (CSRF).');}}
function lo_value($value){return $value===null||$value===''?'Sem dados':(string)$value;}
