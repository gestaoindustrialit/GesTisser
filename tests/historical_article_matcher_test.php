<?php
require_once __DIR__.'/../historical-import/ArticleMatcher.php';
$p=new PDO('sqlite::memory:');$p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$p->exec('CREATE TABLE erp_customers(id INTEGER PRIMARY KEY,code TEXT,name TEXT);INSERT INTO erp_customers VALUES(1,"C1","Cliente 1"),(2,"C2","Cliente 2");CREATE TABLE erp_finished_products(id INTEGER PRIMARY KEY,code TEXT,description TEXT,customer_id INTEGER,customer_product_code TEXT);INSERT INTO erp_finished_products VALUES(10,"REF1","Artigo único",1,"R1"),(20,"REF2","Nome repetido",1,NULL),(21,"REF3","Nome repetido",2,NULL),(30,"REF4","Ráfia-A",1,NULL);');
$p->exec('PRAGMA query_only=ON');
$rows=[
 ['legacy_id'=>'1','artigo_codigo'=>'REF1','artigo_designacao'=>'Artigo único','cliente_codigo'=>'C1'],
 ['legacy_id'=>'2','artigo_codigo'=>'R1'],
 ['legacy_id'=>'3','artigo_designacao'=>'Artigo único'],
 ['legacy_id'=>'4','artigo_designacao'=>'Nome repetido'],
 ['legacy_id'=>'5','artigo_designacao'=>'Nome repetido','cliente_codigo'=>'C2'],
 ['legacy_id'=>'6','artigo_codigo'=>'REF1','artigo_designacao'=>'Nome repetido'],
 ['legacy_id'=>'7','artigo_designacao'=>'Rafia-A'],
 ['legacy_id'=>'8','artigo_designacao'=>'Artigo único','artigo_codigo'=>'INCORRECT'],
 ['legacy_id'=>'9','artigo_codigo'=>'REF1','cliente_codigo'=>'C2'],
 ['legacy_id'=>'10','artigo_codigo'=>'REF1'],['legacy_id'=>'10','artigo_codigo'=>'REF1'],
];
$r=(new HistoricalArticleMatcher($p))->analyze($rows);
$expected=['MATCH_REF_AND_NAME','MATCH_EXACT_REF','MATCH_EXACT_NAME','MULTIPLE_MATCHES','MATCH_EXACT_NAME','MULTIPLE_MATCHES','NO_MATCH','MATCH_EXACT_NAME','NO_MATCH','MATCH_EXACT_REF','MATCH_EXACT_REF'];
foreach($expected as $i=>$status)if($r['rows'][$i]['classification']!==$status)throw new RuntimeException('Classification mismatch row '.$i);
if($r['rows'][0]['article_id']!==10 || $r['rows'][4]['article_id']!==21)throw new RuntimeException('Current article ID not reused');
foreach([3,5,6,7,8,9,10] as $i)if($r['rows'][$i]['automatic'] || $r['rows'][$i]['article_id']!==null)throw new RuntimeException('Unsafe match confirmed: '.$i);
if($r['articles_total']!==10 || $r['duplicate_rows']!==1)throw new RuntimeException('Distinct/duplicate count incorrect');
$path=HistoricalArticleMatcher::reportZip($r);$z=new ZipArchive();if($z->open($path)!==true || $z->getFromName('excecoes_artigos.xlsx')===false)throw new RuntimeException('Exceptions missing');$z->close();unlink($path);
$path=HistoricalSpreadsheet::articleTemplate();$parsed=HistoricalSpreadsheet::read($path);if($parsed['entity']!=='article_matching')throw new RuntimeException('Staging template not recognized');unlink($path);
echo "PASS exact article matching: all five classifications, current IDs, customer scope, conflicting references, preserved accents, duplicate exceptions and XLSX reports\n";
