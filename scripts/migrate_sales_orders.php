<?php
// Explicit development-only migration; refuses to create a missing database.
require_once dirname(__DIR__).'/bootstrap/config.php';
require_once dirname(__DIR__).'/app/Services/SalesOrderSchema.php';
if(PHP_SAPI!=='cli' || !in_array(app_config('env'),['development','gestisser-dev','test'],true)) {fwrite(STDERR,"Migração permitida apenas em desenvolvimento.\n");exit(1);}
$path=app_config('db_path');
if(!is_file($path)){fwrite(STDERR,"Base de desenvolvimento inexistente; instale a cópia gestisser-dev primeiro.\n");exit(1);}
$pdo=new PDO('sqlite:'.$path);$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->exec('PRAGMA foreign_keys=ON');$pdo->exec('PRAGMA busy_timeout=5000');
SalesOrderSchema::migrate($pdo);echo "Estrutura de encomendas de clientes preparada. Nenhuma OF, movimento ou stock alterado.\n";
