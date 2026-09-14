<?php
require_once __DIR__.'/../app/Services/GenericPdfOrderParser.php';
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
check(OrderImportNormalizer::number('1.250,00')===1250.0,'European thousands');
check(OrderImportNormalizer::number('1 250,00')===1250.0,'space thousands');
check(OrderImportNormalizer::number('1250.00')===1250.0,'decimal point');
check(OrderImportNormalizer::date('14/09/2026')==='2026-09-14','European date');
check(OrderImportNormalizer::date('14 Sep 2026')==='2026-09-14','text date');
$text="Encomenda: PO-77\nData: 14-09-2026\nREF Descrição Quantidade\nREF123 SACO DE RÁFIA IMPRESSO 5000 PCS\n55X90 BRANCO CLIENTE XPTO\n\fREF Descrição Quantidade\nTRANSPORTE 25,00\nSUBTOTAL 1.250,00\nTOTAL 1.275,00";
$d=(new GenericPdfOrderParser())->parse($text);
check($d['order_number']==='PO-77','order number');check($d['order_date']==='2026-09-14','order date');
$articles=array_values(array_filter($d['lines'],function($l){return $l['type']==='ARTICLE';}));$types=array_column($d['lines'],'type');
check(count($articles)===1,'headers ignored across pages');check($articles[0]['quantity']===5000.0,'quantity');check($articles[0]['unit_original']==='PCS'&&$articles[0]['unit_normalized']==='UN','unit audit');
check(strpos($articles[0]['description'],'55X90 BRANCO')!==false,'multiline description');check(in_array('SERVICE',$types,true),'transport service');check(in_array('SUBTOTAL',$types,true)&&in_array('TOTAL',$types,true),'totals classified');
echo "order_pdf_parser_test: OK\n";
