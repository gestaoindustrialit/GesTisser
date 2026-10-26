<?php
/** Adapter for the saved roll/ink label records, with no writes to stock or OF. */
class PrintLabelRenderer
{
    private static function hex($value) { $out='';foreach(str_split((string)$value) as $byte)$out.='_'.strtoupper(bin2hex($byte));return $out; }
    public static function zpl(array $label,$kind,array $format,$dpi)
    {
        $width=(float)$format['width_mm'];$height=(float)$format['height_mm'];
        if($width<50||$height<30||$width>300||$height>300||!in_array((int)$dpi,[203,300,600],true))throw new InvalidArgumentException('Dimensões ou resolução incompatíveis.');
        $scale=$dpi/25.4;$w=(int)round($width*$scale);$h=(int)round($height*$scale);$font=max(12,(int)round(2.4*$scale));$x=(int)round(2*$scale);$y=$x;
        $lines=[$kind==='roll'?'ETIQUETA DE ROLO':'ETIQUETA DE TINTA','Entrada: '.$label['entry_number'],'Lote: '.$label['supplier_lot'],(string)$label['article_code'],(string)$label['description']];
        if($kind==='roll')$lines[]='Metros: '.$label['metres'];$lines[]='Peso: '.$label['weight_kg'].' kg';$lines[]='Data: '.$label['label_date'];$lines[]=(string)$label['barcode'];
        $step=(int)floor(($h-2*$x)/count($lines));$font=min($font,max(10,$step-3));$zpl='^XA^CI28^PW'.$w.'^LL'.$h.'^LH0,0';
        foreach($lines as $line){$zpl.='^FO'.$x.','.$y.'^A0N,'.$font.','.$font.'^FB'.($w-2*$x).',1,0,L,0^FH_^FD'.self::hex($line).'^FS';$y+=$step;}
        return $zpl.'^XZ';
    }
    public static function pdf(array $label,$kind,array $format)
    {
        require_once __DIR__.'/../../vendor/setasign/fpdf/fpdf.php';
        // FPDF accepts custom dimensions in the requested order; do not rotate twice.
        $w=(float)$format['width_mm'];$h=(float)$format['height_mm'];
        $pdf=new FPDF($w>$h?'L':'P','mm',[min($w,$h),max($w,$h)]);$pdf->SetMargins(3,3,3);$pdf->SetAutoPageBreak(false);$pdf->AddPage();$pdf->SetFont('Arial','B',8);
        $lines=[$kind==='roll'?'ETIQUETA DE ROLO':'ETIQUETA DE TINTA','Entrada: '.$label['entry_number'],'Lote: '.$label['supplier_lot'],$label['article_code'].' - '.$label['description']];if($kind==='roll')$lines[]='Metros: '.$label['metres'];$lines[]='Peso: '.$label['weight_kg'].' kg';$lines[]='Data: '.$label['label_date'];$lines[]=$label['barcode'];
        $step=($h-6)/count($lines);foreach($lines as $line)$pdf->Cell($w-6,$step,iconv('UTF-8','windows-1252//TRANSLIT',(string)$line),0,1);return $pdf->Output('S');
    }
    public static function productPdf(array $data,array $values,$company)
    {
        require_once __DIR__.'/../../vendor/setasign/fpdf/fpdf.php';
        require_once __DIR__.'/Code128Barcode.php';
        $pdf=new FPDF('P','mm','A4');$pdf->SetAutoPageBreak(false);$pdf->AddPage();$pdf->SetDrawColor(21,35,31);$pdf->SetLineWidth(.45);$pdf->Line(0,80,210,80);$pdf->Rect(5,85,200,138);
        $text=function($x,$y,$value,$size=12,$bold=false)use($pdf){$pdf->SetFont('Arial',$bold?'B':'',$size);$pdf->SetXY($x,$y);$pdf->MultiCell(178,6,iconv('UTF-8','windows-1252//TRANSLIT',(string)$value),0,'L');};
        $text(16,94,$company,20,true);$text(145,94,$values['date']->format('d/m/Y'),14,true);$pdf->Line(16,108,194,108);
        $text(16,111,'CLIENTE',8);$text(16,116,$data['customer_name'],15,true);$text(16,124,$data['locality'],10);$pdf->Line(16,133,194,133);
        $text(16,136,$data['product_code'],12,true);$text(16,144,$data['product_description'],16,true);
        $text(16,166,'QUANTIDADE: '.number_format($values['quantity'],2,',',' '),16,true);$text(110,166,'LOTE: '.$data['lot'],16,true);
        // Reuse the existing Code128 generator: draw its numeric rectangle coordinates.
        $svg=Code128Barcode::svg((string)$data['lot']);preg_match('/viewBox="0 0 ([0-9]+) 58"/',$svg,$vb);preg_match_all('/<rect x="([0-9]+)" y="2" width="([0-9]+)" height="54"\//',$svg,$bars,PREG_SET_ORDER);
        $scale=125/(int)$vb[1];$pdf->SetFillColor(0);foreach($bars as $bar)$pdf->Rect(42.5+(int)$bar[1]*$scale,183,(int)$bar[2]*$scale,18,'F');
        $text(75,203,$data['lot'],10,true);$text(16,214,'OF '.$data['order_number'].' · '.$company,8);
        return $pdf->Output('S');
    }

}
