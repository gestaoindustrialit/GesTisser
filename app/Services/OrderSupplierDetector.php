<?php
declare(strict_types=1);

/**
 * Backwards-compatible service kept for rolling deployments where erp.php may
 * be updated before or after the PDF import service files.
 */
final class OrderSupplierDetector
{
    private $pdo;
    public function __construct(PDO $pdo){$this->pdo=$pdo;}
    public function detect(string $text): array
    {
        $ascii=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',strtoupper(trim($text)));$normalized=preg_replace('/[^A-Z0-9]+/','',$ascii!==false?$ascii:$text);$digits=preg_replace('/\D+/','',$text);$best=null;
        foreach($this->pdo->query('SELECT id,code,name,tax_number,email FROM erp_suppliers WHERE is_active=1')->fetchAll(PDO::FETCH_ASSOC) as $supplier){$score=0;$method='';$tax=preg_replace('/\D+/','',(string)$supplier['tax_number']);$nameAscii=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',strtoupper(trim((string)$supplier['name'])));$name=preg_replace('/[^A-Z0-9]+/','',$nameAscii!==false?$nameAscii:(string)$supplier['name']);if(strlen($tax)>=7&&strpos($digits,$tax)!==false){$score=100;$method='tax_number';}elseif(strlen((string)$supplier['code'])>=3&&preg_match('/\b'.preg_quote((string)$supplier['code'],'/').'\b/iu',$text)){$score=95;$method='supplier_code';}elseif(strlen($name)>=4&&strpos($normalized,$name)!==false){$score=82;$method='name';}elseif((string)$supplier['email']!==''&&stripos($text,(string)$supplier['email'])!==false){$score=80;$method='email';}if($score>($best['confidence']??0))$best=['id'=>(int)$supplier['id'],'name'=>$supplier['name'],'confidence'=>$score,'method'=>$method,'requires_confirmation'=>$score<95];}
        return $best?:['id'=>null,'name'=>'','confidence'=>0,'method'=>'none','requires_confirmation'=>true];
    }
}
