<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/bootstrap/config.php';
require_once dirname(__DIR__).'/app/Services/CentralPrinting.php';
if(PHP_VERSION_ID!==70033){fwrite(STDERR,"Use PHP 7.0.33 CLI.\n");exit(1);}
$path=app_config('db_path');if(!is_file($path)){fwrite(STDERR,"Base existente indisponível.\n");exit(1);}
$pdo=new PDO('sqlite:'.$path);$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->exec('PRAGMA busy_timeout=5000');
$printing=new CentralPrinting($pdo);if($printing->installed())$printing->cleanup(30);
echo "Retenção de impressão aplicada; metadados e pendentes preservados.\n";
