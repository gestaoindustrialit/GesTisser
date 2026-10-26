<?php
require_once __DIR__.'/../app/Services/CentralPrinting.php';
if(isset($argv[1])&&$argv[1]==='--claim'){
    $d=new PDO('sqlite:'.$argv[2]);$d->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$d->exec('PRAGMA busy_timeout=5000');$svc=new CentralPrinting($d);$job=$svc->claim($argv[3]);echo $job?$job['id']:'none';exit;
}
$dir=sys_get_temp_dir().'/print-concurrency-'.bin2hex(random_bytes(4));mkdir($dir,0700);$path=$dir.'/db.sqlite';
$db=new PDO('sqlite:'.$path);$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->exec("CREATE TABLE erp_printers(id INTEGER PRIMARY KEY,name TEXT,network_uri TEXT,is_active INTEGER DEFAULT 1,updated_at TEXT);CREATE TABLE erp_work_centers(id INTEGER PRIMARY KEY,name TEXT,is_active INTEGER DEFAULT 1,default_printer_id INTEGER)");
$svc=new CentralPrinting($db);$svc->migrate($dir.'/backup');$a=$svc->createAgent('PC',1);$svc->setEnabled(true,1);
$db->prepare("INSERT INTO erp_printers(id,name,agent_id,windows_queue,language,connection_type,online,last_seen) VALUES (1,'Zebra',?,'Zebra','zpl','windows_usb',1,CURRENT_TIMESTAMP)")->execute([$a['id']]);
$db->exec("INSERT INTO erp_work_centers(id,name) VALUES(1,'Corte');INSERT INTO erp_print_templates(id,code,name,type_id,format_id,renderer) VALUES(1,'roll','Rolo',2,2,'raw_material_roll_label');INSERT INTO erp_work_center_printers(work_center_id,printer_id,type_id,format_id,is_default) VALUES(1,1,2,2,1)");
$id=$svc->enqueue(1,1,1,0,1,'^XA^XZ','application/zpl',bin2hex(random_bytes(16)));
$command=escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' --claim '.escapeshellarg($path).' '.escapeshellarg($a['id']);$workers=[];
for($i=0;$i<2;$i++){$pipes=[];$process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($process))throw new RuntimeException('Não foi possível criar processo concorrente.');fclose($pipes[0]);$workers[]=[$process,$pipes];}
$results=[];foreach($workers as $worker){$out=stream_get_contents($worker[1][1]);$err=stream_get_contents($worker[1][2]);fclose($worker[1][1]);fclose($worker[1][2]);$exit=proc_close($worker[0]);if($exit)throw new RuntimeException('Agente concorrente falhou: '.$err);$results[]=trim($out);}sort($results);$expected=[$id,'none'];sort($expected);if($results!==$expected)throw new RuntimeException('O mesmo trabalho foi reservado por dois processos.');
$db=null;foreach(glob($dir.'/backup/*') as $f)unlink($f);unlink($dir.'/backup/.htaccess');rmdir($dir.'/backup');unlink($path);rmdir($dir);echo "Dois processos PHP 7.0 concorrentes: uma única reserva.\n";
