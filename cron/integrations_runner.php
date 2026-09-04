<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/bootstrap/app.php';require_once dirname(__DIR__).'/integrations_migrations.php';require_once dirname(__DIR__).'/includes/integrations/IntegrationRunner.php';integrations_migrate(db());
$lock=fopen((string)app_config('paths.storage').'/integrations_runner.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){fwrite(STDERR,"Runner já está ativo.\n");exit(0);}try{$runs=(new IntegrationRunner(db()))->due();echo count($runs)." fluxo(s) executado(s).\n";}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}finally{flock($lock,LOCK_UN);fclose($lock);}
