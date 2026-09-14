<?php
declare(strict_types=1);

final class OrderSupplierDetector
{
    private $pdo;
    public function __construct(PDO $pdo){$this->pdo=$pdo;}
    public function detect(string $text):array
    {
        $normalized=$this->normalize($text);$best=null;
        foreach($this->pdo->query('SELECT id,code,name,tax_number,address,email FROM erp_suppliers WHERE is_active=1')->fetchAll(PDO::FETCH_ASSOC) as $supplier){
            $score=0;$method='';$tax=preg_replace('/\D+/','',(string)$supplier['tax_number']);
            if(strlen($tax)>=7&&strpos(preg_replace('/\D+/','',$text),$tax)!==false){$score=100;$method='tax_number';}
            elseif(strlen((string)$supplier['code'])>=3&&preg_match('/\b'.preg_quote((string)$supplier['code'],'/').'\b/iu',$text)){$score=95;$method='supplier_code';}
            elseif(strlen((string)$supplier['name'])>=4&&strpos($normalized,$this->normalize((string)$supplier['name']))!==false){$score=82;$method='name';}
            elseif((string)$supplier['email']!==''&&stripos($text,(string)$supplier['email'])!==false){$score=80;$method='email';}
            if($score>($best['confidence']??0))$best=['id'=>(int)$supplier['id'],'name'=>$supplier['name'],'confidence'=>$score,'method'=>$method,'requires_confirmation'=>$score<95];
        }
        return $best?:['id'=>null,'name'=>'','confidence'=>0,'method'=>'none','requires_confirmation'=>true];
    }
    private function normalize(string $value):string{$value=strtoupper(trim($value));return preg_replace('/[^A-Z0-9]+/','',iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$value)?:$value);}
}
