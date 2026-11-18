<?php
require_once __DIR__.'/../app/Services/PurchaseOrderPdf.php';
function svg_logo_check($ok,$message){if(!$ok)throw new RuntimeException($message);}
$svg=file_get_contents((getenv('PURCHASE_TEST_LOGO_SVG')?:__DIR__.'/fixtures/outlined_report_logo.svg'));
$uri='data:image/svg+xml;base64,'.base64_encode($svg);
$logo=PurchaseOrderPdf::logo($uri);
$dom=new DOMDocument();$dom->loadXML($svg,LIBXML_NONET);$root=$dom->documentElement;
svg_logo_check(($logo['type']??'')==='form'&&$logo['width']===(float)$root->getAttribute('width')&&$logo['height']===(float)$root->getAttribute('height'),'Dimensões do SVG configurado preservadas.');
preg_match_all('/fill:#([0-9a-f]{6})/i',$svg,$colors);
foreach(array_unique($colors[1]) as $hex){
    $rgb=[];for($i=0;$i<6;$i+=2)$rgb[]=rtrim(rtrim(sprintf('%.8F',hexdec(substr($hex,$i,2))/255),'0'),'.');
    svg_logo_check(strpos($logo['data'],implode(' ',$rgb).' rg')!==false,'Cor do SVG preservada.');
}
svg_logo_check(substr_count($logo['data'],"f\n")+substr_count($logo['data'],"f*\n")===$dom->getElementsByTagName('path')->length,'Todos os caminhos do SVG incorporados.');
$snapshot=['company'=>['name'=>'Empresa teste','logo_data'=>$uri],'order'=>['order_number'=>'ENC-SVG','order_date'=>'2026-11-18','status'=>'Aberta','supplier_name'=>'Fornecedor de teste'],'lines'=>[['quantity'=>20,'unit_code'=>'Kg','unit_price'=>5,'item_code'=>'ART-01','item_description'=>'Artigo de teste']]];
$pdf=PurchaseOrderPdf::render($snapshot);
svg_logo_check(strpos($pdf,'/Subtype /Form')!==false&&strpos($pdf,'/BBox [0 0 1 1]')!==false&&strpos($pdf,'/Logo Do')!==false,'SVG incorporado como Form XObject no PDF.');
svg_logo_check(strpos($pdf,'/Subtype /Image')===false,'SVG mantém vetores, sem conversão raster.');
$snapshot['lines']=array_fill(0,120,$snapshot['lines'][0]);$long=PurchaseOrderPdf::render($snapshot);
preg_match_all('#/Type /Page\b#',$long,$pages);
svg_logo_check(count($pages[0])>1&&substr_count($long,'/Logo Do')===count($pages[0]),'Logótipo repetido em todas as páginas.');
foreach([
 '<svg xmlns="http://www.w3.org/2000/svg"><image href="https://example.invalid/logo.png"/></svg>',
 '<!DOCTYPE svg [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg">&xxe;</svg>',
 '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"><path d="M0 0 A1 1 0 0 1 1 1"/></svg>',
 '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"><path d="M0 0 L"/></svg>',
 '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"><script>alert(1)</script></svg>'
] as $invalid)svg_logo_check(SvgLogoPdf::fromDataUri('data:image/svg+xml;base64,'.base64_encode($invalid))===[],'SVG inválido ou dependência externa não deve gerar logótipo parcial.');
$curves='<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><g transform="translate(1 2) scale(2)"><path fill="#123" fill-rule="evenodd" d="M0 0 2 0 C2 1 3 1 3 2 S4 3 4 4 Q5 4 5 5 T6 6z"/></g></svg>';
$curved=SvgLogoPdf::fromDataUri('data:image/svg+xml;base64,'.base64_encode($curves));
svg_logo_check($curved&&strpos($curved['data'],"f*\n")!==false&&substr_count($curved['data']," c\n")===4,'Curvas, transformações herdadas e preenchimento par/ímpar.');
echo "purchase_order_svg_logo_test: OK — SVG configurado, vetores, cores, multipágina e validação.\n";
