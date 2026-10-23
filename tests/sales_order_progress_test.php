<?php
require_once __DIR__.'/../app/Services/SalesOrderProgress.php';
$lines=[['quantity'=>100,'planned'=>60,'produced'=>50,'delivered'=>20,'unit_code'=>'kg'],['quantity'=>10,'planned'=>10,'produced'=>10,'delivered'=>0,'unit_code'=>'un']];
$before=$lines;$summary=SalesOrderProgress::summary($lines);
if($summary['planned']!==80.0||$summary['produced']!==75.0||$summary['delivered']!==10.0)throw new RuntimeException('Progress must average per-line percentages without adding kg to units.');
if($summary['production_state']!=='Produção parcial'||$summary['delivery_state']!=='Entrega parcial')throw new RuntimeException('Production and delivery must stay independent.');
if($lines!==$before)throw new RuntimeException('Progress cannot mutate commercial quantities.');
$done=SalesOrderProgress::summary([['quantity'=>10,'planned'=>10,'produced'=>15,'delivered'=>0]]);
if($done['produced']!==100.0||$done['delivery_state']!=='Sem entregas')throw new RuntimeException('Overproduction capped visually; production never implies delivery.');
if(SalesOrderProgress::summary([])['production_state']!=='Sem artigos')throw new RuntimeException('No lines cannot imply completion.');
if(SalesOrderProgress::summary([['quantity'=>10,'planned'=>10,'produced'=>0]],[['status'=>'Em produção']])['production_state']!=='Em produção')throw new RuntimeException('An active OF must show production even before its first recorded quantity.');
echo "Sales order progress: mixed units, independent delivery and read-only calculation passed.\n";
