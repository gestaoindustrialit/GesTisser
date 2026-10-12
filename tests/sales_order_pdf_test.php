<?php
require_once __DIR__.'/../app/Services/SalesOrderPdf.php';
$order=['order_number'=>'EC-42','customer_name'=>'RAÇÕES (ZÊZERE) \\ SA','customer_code'=>'C1','notes'=>str_repeat('Observações completas e acentuadas. ',100),'order_date'=>'2026-10-12'];
$lines=[];for($i=1;$i<=200;$i++)$lines[]=['article_code'=>'ART-'.$i,'description'=>str_repeat('Descrição longa do artigo ',10),'quantity'=>10,'unit_code'=>'un','unit_price'=>null,'discount_percent'=>0,'line_total'=>null];
$pdf=SalesOrderPdf::render($order,$lines);
if(strpos($pdf,'%PDF-1.4')!==0||strpos($pdf,'ART-200')===false||!preg_match('~/Type /Pages /Count ([0-9]+)~',$pdf,$m)||(int)$m[1]<2)throw new RuntimeException('All 200 lines must survive paginated export.');
preg_match('/startxref\n([0-9]+)/',$pdf,$xref);if(substr($pdf,(int)$xref[1],4)!=='xref')throw new RuntimeException('Invalid xref offset.');
echo "Sales order PDF: multipage, complete lines and valid xref passed.\n";
