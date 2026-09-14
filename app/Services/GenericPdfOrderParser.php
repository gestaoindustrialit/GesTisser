<?php
declare(strict_types=1);
require_once __DIR__.'/OrderImportNormalizer.php';

final class GenericPdfOrderParser
{
    public function parse(string $text, array $context = []): array
    {
        $result=['supplier'=>$context['supplier']??[],'order_number'=>'','order_date'=>null,'delivery_date'=>null,'currency'=>'EUR','lines'=>[],'warnings'=>[]];
        if(preg_match('/(?:encomenda|order|facture\s+proforma)\s*(?:n[.ºo°]*|number|no)?\s*[:#]?\s*([A-Z0-9.\/_-]+)/iu',$text,$m))$result['order_number']=$m[1];
        if(preg_match('/(?:data|date)\s*:?\s*(\d{1,2}[\/-]\d{1,2}[\/-]\d{4}|\d{4}-\d{2}-\d{2}|\d{1,2}\s+[A-Za-z]{3}\s+\d{4})/iu',$text,$m))$result['order_date']=OrderImportNormalizer::date($m[1]);
        if(preg_match('/(?:entrega|delivery|livraison)\D{0,20}(\d{1,2}[\/-]\d{1,2}[\/-]\d{4}|\d{4}-\d{2}-\d{2}|\d{1,2}\s+[A-Za-zéûôîàèùç]{3,12}\s+\d{4})/iu',$text,$m))$result['delivery_date']=OrderImportNormalizer::date($m[1]);
        $physical=preg_split('/\R/u',$text); $pending=null;
        foreach($physical as $raw){$line=trim($raw);if($line===''||$this->isHeader($line))continue;
            $type=$this->classify($line);
            if($type!=='ARTICLE'){$this->flush($result,$pending);$result['lines'][]=$this->baseLine($result,$line,$type);continue;}
            $pattern='~^([A-Z0-9][A-Z0-9._/-]{1,})\s+(.+?)\s+(\d[\d .]*?(?:[,.]\d+)?)\s+(PALETE|ROLO|UND|PCS|KGS|ROL|UN|PC|KG|ML|MT|M)(?:\s+(\d[\d .]*?(?:[,.]\d+)?)(?:\s+(\d[\d .]*?(?:[,.]\d+)?))?)?(?:\s+(\d{1,2}[/-]\d{1,2}[/-]\d{4}|\d{4}-\d{2}-\d{2}))?$~iu';
            if(preg_match($pattern,$line,$m)){$this->flush($result,$pending);$u=OrderImportNormalizer::unit($m[4]);$pending=array_merge($this->baseLine($result,$raw,'ARTICLE'),['supplier_reference'=>$m[1],'description'=>trim($m[2]),'quantity'=>OrderImportNormalizer::number($m[3]),'unit_original'=>$u['unit_original'],'unit_normalized'=>$u['unit_normalized'],'unit_price'=>isset($m[5])&&$m[5]!==''?OrderImportNormalizer::number($m[5]):null,'total'=>isset($m[6])&&$m[6]!==''?OrderImportNormalizer::number($m[6]):null,'delivery_date'=>isset($m[7])?OrderImportNormalizer::date($m[7]):null]);}
            elseif(preg_match('~^(.+?)\s+(\d[\d .]*(?:[,.]\d+)?)\s+(PALETE|ROLO|UND|PCS|KGS|ROL|UN|PC|KG|ML|MT|M)\s+(\d[\d .]*(?:[,.]\d+)?)\s+(\d[\d .]*(?:[,.]\d+)?)(?:\s+EUR)?$~iu',$line,$columns)){$this->flush($result,$pending);$u=OrderImportNormalizer::unit($columns[3]);$pending=array_merge($this->baseLine($result,$raw,'ARTICLE'),['description'=>trim($columns[1]),'quantity'=>OrderImportNormalizer::number($columns[2]),'unit_original'=>$u['unit_original'],'unit_normalized'=>$u['unit_normalized'],'unit_price'=>OrderImportNormalizer::number($columns[4]),'total'=>OrderImportNormalizer::number($columns[5])]);}
            elseif($pending){$pending['description'].=' '.$line;$pending['raw_text'].="\n".$raw;}
            else {$result['lines'][]=$this->baseLine($result,$raw,'COMMENT');}
        }
        $this->flush($result,$pending);
        foreach($result['lines'] as &$row){if($row['type']==='ARTICLE'&&$row['quantity']!==null&&$row['unit_price']!==null&&$row['total']!==null){$difference=abs($row['quantity']*$row['unit_price']-$row['total']);$tolerance=max(.02,abs($row['total'])*.001);if($difference>$tolerance){$row['status']='conflict';$row['warnings'][]='Quantidade × preço unitário não corresponde ao total da linha.';}}}
        return $result;
    }
    private function isHeader(string $line): bool{return (bool)preg_match('/^(?:ref(?:er[eê]ncia)?|c[oó]digo)\b.*(?:descri[cç][aã]o|quantidade|qtd)/iu',$line);}
    private function classify(string $line): string {if(preg_match('/^(?:subtotal|iva|vat)\b/iu',$line))return 'SUBTOTAL';if(preg_match('/^(?:total)\b/iu',$line))return 'TOTAL';if(preg_match('/^(?:portes|transporte|frete)\b/iu',$line))return 'SERVICE';if(preg_match('/^(?:desconto|embalagem|custos? administrativos?)\b/iu',$line))return 'CHARGE';if(preg_match('/^(?:observa[cç][oõ]es?|nota)\b/iu',$line))return 'COMMENT';return 'ARTICLE';}
    private function baseLine(array $result,string $raw,string $type):array{return ['line_number'=>count($result['lines'])+1,'type'=>$type,'supplier_reference'=>'','description'=>trim($raw),'quantity'=>null,'unit_original'=>'','unit_normalized'=>'','unit_price'=>null,'discount'=>null,'total'=>null,'delivery_date'=>null,'raw_text'=>$raw,'matched_item_id'=>null,'confidence'=>0,'status'=>$type==='ARTICLE'?'unrecognized':'ignored','warnings'=>[]];}
    private function flush(array &$result,array &$pending=null){if($pending!==null){$pending['line_number']=count($result['lines'])+1;$pending['status']='confirmation';$result['lines'][]=$pending;$pending=null;}}
}
