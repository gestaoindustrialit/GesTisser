<?php
/** Dependency-free A4 export for hosting installations without Composer/vendor. */
final class SalesOrderPdf
{
    public static function render(array $order, array $lines): string
    {
        $pages=[];$content='';$y=0;
        $text=function($value,$size=9,$bold=false)use(&$content,&$y,&$pages,$order){
            if($y<55){if($content!=='')$pages[]=$content;$content='';$y=792;
                $title=self::escape('Pré-visualização · Encomenda '.($order['order_number']??''));
                $content.="BT /F2 16 Tf 35 $y Td ($title) Tj ET\n";$y-=28;
            }
            $escaped=self::escape((string)$value);$font=$bold?'F2':'F1';
            $content.="BT /$font $size Tf 35 $y Td ($escaped) Tj ET\n";$y-=max(14,$size+5);
        };
        $wrap=function($value,$bold=false)use($text){
            // Wrap encoded text by bytes: every WinAnsi glyph is a single byte.
            $encoded=self::encode((string)$value);
            foreach(explode("\n",wordwrap($encoded,95,"\n",true)) as $row)$text(self::decode($row),9,$bold);
        };
        $wrap('Cliente: '.($order['customer_code']??'').' — '.($order['customer_name']??''),true);
        $wrap('Data: '.self::date($order['order_date']??'').' | Entrega prevista: '.self::date($order['expected_date']??''));
        $wrap('Referência: '.($order['customer_reference']??'').' | Estado: '.($order['status']??''));
        $wrap('Origem: '.($order['source_system']??''));$text('');
        foreach($lines as $index=>$line){
            $wrap(($index+1).'. '.($line['article_code']??''),true);
            $wrap($line['description']??'');
            $wrap('Qtd.: '.self::number($line['quantity']??null,3).' '.(($line['unit_code']??'')?:'Unidade não registada'));
            $wrap('Preço U.: '.self::number($line['unit_price']??null,4).' EUR | Desc.: '.self::number($line['discount_percent']??null,2).' % | Valor: '.self::number($line['line_total']??null,2).' EUR');
            $wrap('Entrega prevista: '.self::date(($line['expected_date']??'')?:($order['expected_date']??'')));$text('');
        }
        $text('Observações',10,true);$wrap(($order['notes']??'')?:'Sem observações.');$text('');
        $wrap('Pré-visualização sem número de emissão. Utilize Gerar confirmação para emitir o documento.');
        $wrap('Valores comerciais registados na encomenda. Não constitui documento fiscal.');
        if($content!=='')$pages[]=$content;
        $objects=[1=>'<< /Type /Catalog /Pages 2 0 R >>',3=>'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',4=>'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>'];$kids=[];$count=count($pages);
        foreach($pages as $index=>$stream){$pageId=5+$index*2;$streamId=$pageId+1;$kids[]="$pageId 0 R";
            $footer=self::escape('Página '.($index+1).' / '.$count);$stream.="BT /F1 8 Tf 480 28 Td ($footer) Tj ET\n";
            $objects[$pageId]="<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents $streamId 0 R >>";
            $objects[$streamId]='<< /Length '.strlen($stream).">>\nstream\n".$stream.'endstream';
        }
        $objects[2]='<< /Type /Pages /Count '.$count.' /Kids ['.implode(' ',$kids).'] >>';ksort($objects);
        $pdf="%PDF-1.4\n";$offsets=[0];foreach($objects as $id=>$object){$offsets[$id]=strlen($pdf);$pdf.="$id 0 obj\n$object\nendobj\n";}
        $xref=strlen($pdf);$size=count($objects)+1;$pdf.="xref\n0 $size\n0000000000 65535 f \n";
        for($id=1;$id<$size;$id++)$pdf.=sprintf("%010d 00000 n \n",$offsets[$id]);
        return $pdf."trailer\n<< /Size $size /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
    }
    private static function number($value,$digits){return $value===null?'Não registado':number_format((float)$value,$digits,',',' ');}
    private static function date($value){$time=$value?strtotime($value):false;return $time===false?'Não registada':date('d/m/Y',$time);}
    private static function encode($value){return iconv('UTF-8','Windows-1252//TRANSLIT//IGNORE',$value);}
    private static function decode($value){return iconv('Windows-1252','UTF-8',$value);}
    private static function escape($value){return str_replace(["\\",'(',')',"\r","\n"],["\\\\",'\\(','\\)',' ',' '],self::encode($value));}
}
