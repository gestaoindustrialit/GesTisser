<?php
/** Reuses the ERP Code128 and central print transports. */
class PreparedInkLabelRenderer
{
    public static function lines(array $b)
    {
        return ['TISSER — TINTA PREPARADA',$b['color_code'].' — '.$b['color_name'],'Pantone: '.$b['pantone'],$b['mix_code'].' · V'.$b['version'],$b['code'].' · Lote '.$b['lot'],'Inicial: '.number_format($b['initial_mg']/1000000,3,',','').' kg','Preparação: '.$b['prepared_at'],'Colorista: '.$b['operator']];
    }
    private static function dimensions(array $format)
    {
        $w=(float)$format['width_mm'];$h=(float)$format['height_mm'];
        if($w<100||$w>300||$h<50||$h>300)throw new InvalidArgumentException('A etiqueta de tinta preparada requer pelo menos 100 × 50 mm.');return [$w,$h];
    }
    public static function zpl(array $b,array $format,$dpi)
    {
        list($w,$h)=self::dimensions($format);if(!in_array((int)$dpi,[203,300,600],true))throw new InvalidArgumentException('DPI inválido.');
        $scale=$dpi/25.4;$font=(int)round(2.3*$scale);$x=(int)round(3*$scale);$y=$x;$step=(int)round(3.5*$scale);
        $z='^XA^CI28^PW'.(int)round($w*$scale).'^LL'.(int)round($h*$scale);
        foreach(self::lines($b) as $line){$hex='';foreach(str_split($line) as $byte)$hex.='_'.strtoupper(bin2hex($byte));$z.='^FO'.$x.','.$y.'^A0N,'.$font.','.$font.'^FB'.(int)round(($w-6)*$scale).',1,0,L,0^FH_^FD'.$hex.'^FS';$y+=$step;}
        $z.='^FO'.$x.','.$y.'^BY'.max(1,(int)round($scale*.20)).'^BCN,'.(int)round(9*$scale).',Y,N,N^FD'.$b['code'].'^FS^XZ';return $z;
    }
    public static function pdf(array $b,array $format)
    {
        require_once __DIR__.'/../../vendor/setasign/fpdf/fpdf.php';require_once __DIR__.'/Code128Barcode.php';
        list($w,$h)=self::dimensions($format);$pdf=new FPDF($w>$h?'L':'P','mm',[min($w,$h),max($w,$h)]);$pdf->SetMargins(3,3,3);$pdf->SetAutoPageBreak(false);$pdf->AddPage();$pdf->SetFont('Arial','B',7);
        foreach(self::lines($b) as $line)$pdf->Cell($w-6,3.6,iconv('UTF-8','windows-1252//TRANSLIT',$line),0,1);
        $svg=Code128Barcode::svg($b['code']);preg_match('/viewBox="0 0 ([0-9]+) 58"/',$svg,$vb);preg_match_all('/<rect x="([0-9]+)" y="2" width="([0-9]+)" height="54"\//',$svg,$bars,PREG_SET_ORDER);
        $scale=($w-10)/(int)$vb[1];foreach($bars as $bar)$pdf->Rect(5+(int)$bar[1]*$scale,33,(int)$bar[2]*$scale,9,'F');$pdf->SetXY(3,43);$pdf->Cell($w-6,4,$b['code'],0,1,'C');return $pdf->Output('S');
    }
}
