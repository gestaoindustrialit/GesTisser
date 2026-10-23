<?php
// Explicit CLI migration; production requires --activate-production and an existing DB.
require_once dirname(__DIR__).'/bootstrap/config.php';
require_once dirname(__DIR__).'/app/Services/SalesOrderSchema.php';
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Use o terminal ou a ativação administrativa em Encomendas de clientes.');}
$activate=in_array('--activate-production',$argv,true);
if(!$activate&&!in_array(app_config('env'),['development','gestisser-dev','test'],true)){fwrite(STDERR,"Para ativar em produção use --activate-production após criar uma cópia de segurança.\n");exit(1);}
$path=app_config('db_path');
if(!is_file($path)){fwrite(STDERR,"Base de dados inexistente; a migração não cria nem substitui a base.\n");exit(1);}
$pdo=new PDO('sqlite:'.$path);$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->exec('PRAGMA foreign_keys=ON');$pdo->exec('PRAGMA busy_timeout=5000');
if($activate)SalesOrderSchema::activate($pdo);else SalesOrderSchema::migrate($pdo);echo "Estrutura de encomendas de clientes preparada. Nenhuma OF, movimento ou stock alterado.\n";
