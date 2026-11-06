<?php
require_once __DIR__.'/ProductionDossierPdf.php';
/** Standalone purchase PDF, using the native report primitives (no vendor, fonts or temp files). */
final class PurchaseOrderPdf
{
    private $snapshot;
    private $pages=[];
    private $content='';
    private $y=0;
    private $logo=[];
    public static function render(array $snapshot): string
    {
        $pdf=new self();$pdf->snapshot=$snapshot;
        $pdf->logo=ProductionDossierPdf::reportImage((string)($snapshot['company']['logo_data']??''));
        $pdf->startPage();$total=0.0;$complete=true;
        foreach($snapshot['lines'] as $line){
            $price=$line['unit_price']??null;$amount=$price===null?null:(float)$line['quantity']*(float)$price;
            $fields=[self::quantity($line['quantity']),(string)($line['unit_code']??''),self::price($price),(string)($line['pantone']??''),(string)($line['item_code']??'')."\n".(string)($line['item_description']??''),(string)($line['color_name']??''),self::number($amount,2)];
            $cells=[];$rowCount=1;$widths=[45,21,53,39,129,124,49];
            foreach($fields as $i=>$field){$cells[$i]=self::wrap($field,$widths[$i],$i===4?7.5:8);$rowCount=max($rowCount,count($cells[$i]));}
            $offset=0;
            while($offset<$rowCount){
                $capacity=(int)floor(($pdf->y-158-12)/11);if($capacity<2){$pdf->startPage();continue;}
                $count=min($capacity,$rowCount-$offset);$height=max(32,$count*11+12);$bottom=$pdf->y-$height;
                $pdf->rect(30,$bottom,535,$height,[1,1,1],true);foreach([86,116,180,230,370,505] as $x)$pdf->line($x,$bottom,$x,$pdf->y,[.86,.89,.92]);
                foreach($cells as $i=>$rows){$x=[80,90,174,184,235,375,559][$i];$right=in_array($i,[0,2,6],true);foreach(array_slice($rows,$offset,$count) as $j=>$text)$pdf->text($x,$pdf->y-14-$j*11,$i===4?7.5:8,$text,$i===4&&$offset+$j===0,$right);}
                $offset+=$count;$pdf->y=$bottom;
            }
            if($amount===null)$complete=false;else $total+=$amount;
        }
        if($pdf->y<220)$pdf->startPage(false);
        $pdf->rect(370,$pdf->y-38,195,28,[.95,.96,.98]);$pdf->text(380,$pdf->y-28,9,'Total sem IVA (EUR)',true);$pdf->text(555,$pdf->y-28,10,$complete?self::number($total,2):'Por apurar',true,true);$pdf->y-=58;
        $notes=trim((string)($snapshot['order']['notes']??''));
        if($notes!==''){
            if($pdf->y<185)$pdf->startPage(false);$pdf->text(30,$pdf->y,9,'Observações',true);$pdf->y-=15;
            foreach(self::wrap($notes,535,8) as $row){if($pdf->y<158)$pdf->startPage(false);$pdf->text(30,$pdf->y,8,$row);$pdf->y-=11;}
        }
        $pdf->pages[]=$pdf->content;return $pdf->document();
    }
    private function startPage(bool $table=true)
    {
        if($this->content!=='')$this->pages[]=$this->content;$this->content='';
        $s=$this->snapshot;$company=$s['company'];$supplier=$s['order'];$order=$s['order'];
        if($this->logo){$scale=min(145/max(1,$this->logo['width']),42/max(1,$this->logo['height']));$w=$this->logo['width']*$scale;$h=$this->logo['height']*$scale;$this->content.=sprintf("q %.2F 0 0 %.2F 30 %.2F cm /Logo Do Q\n",$w,$h,821-$h);}
        else{$this->rect(30,782,145,39,[.12,.15,.18]);$this->text(39,795,19,trim((string)$company['name'])?:'TISSER',true,false,[1,1,1]);}
        $this->text(307,807,14,'ENCOMENDA',true);$this->text(307,792,10,'A FORNECEDOR');$this->text(565,772,13,(string)$order['order_number'],true,true);
        $this->line(30,761,565,761,[.18,.23,.28],1);
        $left=746;$right=744;
        $left=$this->paragraph(30,$left,251,(string)($company['name']??''),9,true);
        $left=$this->paragraph(30,$left,251,(string)($company['address']??''),8);
        foreach(['phone'=>'Tel.','fax'=>'Fax','email'=>'Email','tax_number'=>'NIF'] as $key=>$label)if(trim((string)($company[$key]??''))!=='')$left=$this->paragraph(30,$left,251,$label.': '.$company[$key],8);
        $this->text(307,$right,8,'Data: '.self::date($order['order_date']));$this->text(565,$right,8,'Moeda: EUR',false,true);$right-=16;
        $this->text(307,$right,7,'Original');$right-=18;
        $this->text(307,$right,8,'Fornecedor',true);$right-=14;
        $right=$this->paragraph(307,$right,258,(string)($supplier['supplier_name']??''),9,true);
        $right-=5;
        $this->text(307,$right,8,'Morada',true);$right-=13;
        $address=trim((string)($supplier['address']??'').' '.(string)($supplier['address_2']??''));
        $right=$this->paragraph(307,$right,258,$address?:'Não registada',8);
        $right=$this->paragraph(307,$right,258,trim((string)($supplier['postal_code']??'').' '.(string)($supplier['city']??'')),8,true);
        $right=$this->paragraph(307,$right,258,(string)($supplier['country']??''),8);
        $bar=min($left,$right)-12;
        if($bar<280)throw new RuntimeException('Os dados de identificação são demasiado extensos para o documento A4.');
        $this->line(30,$bar,565,$bar,[.8,.84,.88]);$bar-=14;
        $bar=$this->paragraph(30,$bar,535,'Encomenda '.$order['order_number'].' · Data da encomenda: '.self::date($order['order_date']).' · Estado: '.$order['status'],7.5);
        if(trim((string)($order['supplier_reference']??''))!=='')$bar=$this->paragraph(30,$bar,535,'Referência do fornecedor: '.$order['supplier_reference'],7.5);
        if(!empty($order['expected_date']))$bar=$this->paragraph(30,$bar,535,'Entrega prevista: '.self::date($order['expected_date']),7.5);
        $this->y=$bar-8;
        if($table){
            $this->rect(30,$this->y-24,535,24,[.94,.95,.97]);
            foreach([[35,'Quant.'],[90,'Un.'],[120,'Preço U.'],[184,'Pantone'],[235,'Código / descrição'],[375,'Cor'],[511,'Total EUR']] as $heading)$this->text($heading[0],$this->y-16,7.5,$heading[1],true);
            $this->y-=24;
        }
        if($this->y<200)throw new RuntimeException('Os dados do cabeçalho são demasiado extensos para o documento A4.');
    }
    private function paragraph(float $x,float $y,float $width,string $value,float $size=8,bool $bold=false): float
    {
        foreach(self::wrap($value,$width,$size) as $row){$this->text($x,$y,$size,$row,$bold);$y-=11;}return $y;
    }
    private function text(float $x,float $y,float $size,string $value,bool $bold=false,bool $right=false,array $colour=[.12,.16,.2])
    {
        $encoded=self::encode($value);if($right)$x-=self::width($encoded,$size);
        $safe=str_replace(['\\','(',')',"\r","\n"],['\\\\','\\(','\\)',' ',' '],$encoded);
        $this->content.=sprintf("BT %.3F %.3F %.3F rg /%s %.2F Tf %.2F %.2F Td (%s) Tj ET\n",$colour[0],$colour[1],$colour[2],$bold?'F2':'F1',$size,$x,$y,$safe);
    }
    private function rect(float $x,float $y,float $width,float $height,array $fill,bool $stroke=false)
    {
        $this->content.=sprintf("q %.3F %.3F %.3F rg .86 .89 .92 RG .4 w %.2F %.2F %.2F %.2F re %s Q\n",$fill[0],$fill[1],$fill[2],$x,$y,$width,$height,$stroke?'B':'f');
    }
    private function line(float $x1,float $y1,float $x2,float $y2,array $colour,float $width=.4)
    {
        $this->content.=sprintf("q %.3F %.3F %.3F RG %.2F w %.2F %.2F m %.2F %.2F l S Q\n",$colour[0],$colour[1],$colour[2],$width,$x1,$y1,$x2,$y2);
    }
    private function document(): string
    {
        $objects=[1=>'<< /Type /Catalog /Pages 2 0 R >>',3=>'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',4=>'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>'];$next=5;$resources='/Font << /F1 3 0 R /F2 4 0 R >>';
        if($this->logo){$data=$this->logo['data'];$decode=$this->logo['filter']==='FlateDecode'?' /DecodeParms << /Predictor 15 /Colors 3 /BitsPerComponent 8 /Columns '.$this->logo['width'].' >>':'';$objects[$next]='<< /Type /XObject /Subtype /Image /Width '.$this->logo['width'].' /Height '.$this->logo['height'].' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /'.$this->logo['filter'].$decode.' /Length '.strlen($data).">>\nstream\n".$data."\nendstream";$resources.=' /XObject << /Logo '.$next.' 0 R >>';$next++;}
        $kids=[];$count=count($this->pages);$terms=[];

        foreach($this->pages as $index=>$stream){$this->content=$stream;$this->text(30,138,7,'Valores sem IVA. Não constitui documento fiscal.');$y=120;foreach($terms as $term){$this->text(30,$y,6.7,$term,true);$y-=9;}
            $this->line(30,59,565,59,[.8,.84,.88]);$this->text(30,46,6.5,'Encomenda '.$this->snapshot['order']['order_number']);$this->text(565,46,6.5,'Página '.($index+1).' / '.$count,false,true);
            $this->text(30,34,6.5,'Processado por computador');$this->text(565,34,6.5,'GesTisser',true,true);
            $pageId=$next++;$streamId=$next++;$kids[]=$pageId.' 0 R';$objects[$pageId]='<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << '.$resources.' >> /Contents '.$streamId.' 0 R >>';$objects[$streamId]='<< /Length '.strlen($this->content).">>\nstream\n".$this->content."endstream";
        }
        $objects[2]='<< /Type /Pages /Count '.$count.' /Kids ['.implode(' ',$kids).'] >>';ksort($objects);$pdf="%PDF-1.4\n";$offsets=[0];foreach($objects as $id=>$object){$offsets[$id]=strlen($pdf);$pdf.="$id 0 obj\n$object\nendobj\n";}$xref=strlen($pdf);$size=count($objects)+1;$pdf.="xref\n0 $size\n0000000000 65535 f \n";for($id=1;$id<$size;$id++)$pdf.=sprintf("%010d 00000 n \n",$offsets[$id]);return $pdf."trailer\n<< /Size $size /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
    }
    private static function wrap(string $value,float $width,float $size): array
    {
        $value=self::encode($value);if(trim($value)==='')return [];$rows=[];
        foreach(explode("\n",str_replace("\r",'', $value)) as $paragraph){$row='';foreach(str_split($paragraph) as $character){if(self::width($row.$character,$size)>$width){$break=strrpos($row,' ');if($break!==false&&$break>0){$rows[]=substr($row,0,$break);$row=ltrim(substr($row,$break+1));}else{$rows[]=$row;$row='';}}$row.=$character;}$rows[]=rtrim($row);}
        return array_map(function($row){return iconv('Windows-1252','UTF-8',$row);},$rows);
    }
    private static function width(string $encoded,float $size): float
    {
        // Standard Helvetica glyph advances; accents use their base-letter widths.
        static $map=null;if($map===null){$map=array_combine(str_split('ABCDEFGHIJKLMNOPQRSTUVWXYZ'),[667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611]);$map+=array_combine(str_split('abcdefghijklmnopqrstuvwxyz'),[556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500]);$map+=[' '=>278,'.'=>278,','=>278,':'=>278,';'=>278,'-'=>333,'/'=>278,'('=>333,')'=>333,'['=>278,']'=>278,'I'=>278,'|'=>260];}
        $sum=0;foreach(str_split($encoded) as $character){$plain=$character;if(ord($character)>127){$plain=@iconv('Windows-1252','ASCII//TRANSLIT//IGNORE',$character);if(!is_string($plain)||strlen($plain)!==1)$plain='?';}$sum+=$map[$plain]??556;}return $sum*$size/1000;
    }
    private static function encode(string $value): string {$encoded=@iconv('UTF-8','Windows-1252//TRANSLIT//IGNORE',$value);return is_string($encoded)?$encoded:'';}
    private static function quantity($value): string {return rtrim(rtrim(number_format((float)$value,4,',',' '),'0'),',');}
    private static function number($value,int $digits): string {return $value===null?'—':number_format((float)$value,$digits,',',' ');}
    private static function price($value): string {if($value===null)return '—';$text=number_format((float)$value,4,',',' ');return substr($text,-1)==='0'?substr($text,0,-1):$text;}
    private static function date($value): string {$time=$value?strtotime($value):false;return $time===false?'—':date('d/m/Y',$time);}
}
