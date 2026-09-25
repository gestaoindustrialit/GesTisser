<?php
declare(strict_types=1);

/** Designed, dependency-free A4 dossier used when the optional mPDF package is absent. */
final class ProductionDossierPdf
{
    public static function render(array $d, string $artworkDataUri = '', string $documentNumber = 'DOC-PRD-001', string $logoDataUri = '', string $companyName = 'TISSER'): string
    {
        $order = (array) ($d['order'] ?? []);
        $snapshot = (array) ($d['snapshot'] ?? []);
        $operations = (array) ($d['operations'] ?? []);
        $jpeg = self::jpegFromDataUri($artworkDataUri);
        $logo = self::jpegFromDataUri($logoDataUri);
        $dash = '-';
        $value = function ($candidate) use ($dash) { $candidate = trim((string) $candidate); return $candidate === '' ? $dash : $candidate; };
        $orderNo = $value($order['order_number'] ?? '');
        $orderRef = $value($order['customer_order_reference'] ?? $snapshot['_order']['customer_order'] ?? '');
        $article = $value($order['article_code'] ?? $snapshot['customer_product_code'] ?? $snapshot['code'] ?? '');
        $description = $value($order['article_description'] ?? $snapshot['description'] ?? '');
        $dimensions = trim((string) ($snapshot['width'] ?? '') . ' x ' . (string) ($snapshot['length'] ?? ''), ' x');
        $quantity = $value($order['planned_quantity'] ?? '');
        $unit = trim((string) ($snapshot['unit_code'] ?? $snapshot['unit'] ?? ''));
        if ($unit !== '') $quantity .= ' ' . $unit;
        $roll = $value($snapshot['printer_roll_measure'] ?? '');
        if ($roll !== $dash && stripos($roll, 'rolo') !== 0) $roll = 'Rolo de ' . $roll;

        $content = '';
        // Cabeçalho limpo, sem caixa exterior.
        if ($logo !== '') self::image($content, 'Logo', $logo, 30, 792, 112, 34);
        else { self::rect($content, 30, 792, 112, 34, false, [0.12, 0.12, 0.12]); self::text($content, 40, 803, 14, self::shorten($companyName, 14), true, [1, 1, 1]); }
        self::text($content, 157, 811, 16, 'FOLHA DE ACOMPANHAMENTO', true, [0, 0, 0]);
        self::text($content, 216, 796, 10, 'ORDEM DE FABRICO', true, [0, 0, 0]);
        self::text($content, 565, 811, 8, $companyName, true, [0, 0, 0], true);
        self::text($content, 565, 797, 7, date('d/m/Y'), false, [0, 0, 0], true);
        self::line($content, 28, 784, 567, 784, 1.3);

        self::sectionBar($content, 28, 758, 539, 18, 'DADOS PRINCIPAIS DA ENCOMENDA');
        self::fieldBox($content, 28, 704, 176, 46, 'ENCOMENDA', $orderRef, 16, true);
        self::fieldBox($content, 210, 704, 111, 46, 'QUANTIDADE', $quantity, 16, true);
        self::rect($content, 327, 704, 240, 46, true, [0.97, 0.98, 0.99]);
        self::text($content, 335, 739, 7, 'OF', true, [.32, .38, .39]);
        self::text($content, 335, 717, 15, $orderNo, true);
        self::barcode128($content, 430, 719, $orderNo, 125, 20);

        self::sectionBar($content, 28, 678, 539, 18, 'IDENTIFICACAO DO CLIENTE E DO ARTIGO');
        self::fieldBox($content, 28, 650, 271, 28, 'CLIENTE', $value($order['customer_name'] ?? $snapshot['customer_name'] ?? ''), 9.5);
        self::fieldBox($content, 299, 650, 268, 28, 'ARTIGO / N. REF.', $article, 9.5);
        self::fieldBox($content, 28, 618, 539, 28, 'DESCRICAO', $description, 10);
        $techY = 586; $techW = [132, 158, 120, 129]; $techX = 28;
        foreach ([['MATERIAL',$snapshot['material']??''],['COMPOSICAO',$snapshot['composition']??''],['DIMENSOES',$dimensions],['GRAMAGEM',$snapshot['grammage']??'']] as $i=>$cell) { self::fieldBox($content,$techX,$techY,$techW[$i],28,$cell[0],$value($cell[1]),$i<2?7.5:9); $techX += $techW[$i]; }
        self::fieldBox($content,28,554,176,28,'COR DO FIO',$value($snapshot['thread_color']??''),9);
        self::fieldBox($content,204,554,190,28,'COSTURA',$value($snapshot['seam_type']??''),9);
        $due = trim((string)($order['due_date']??'')); $dueTime = $due === '' ? false : strtotime($due);
        self::fieldBox($content,394,554,173,28,'DATA PREVISTA',$dueTime?date('d/m/Y',$dueTime):$dash,9);

        self::sectionBar($content,28,528,539,18,'MAQUETA DO ARTIGO / REFERENCIA VISUAL');
        self::rect($content,28,318,350,210,true,[1,1,1]);
        self::rect($content,384,318,183,210,true,[1,1,1]);
        if ($jpeg !== '') self::image($content,'Artwork',$jpeg,38,326,330,192);
        else self::text($content,203,418,8,'SEM MAQUETA ASSOCIADA AO ARTIGO',true,[.4,.4,.4],true);
        self::text($content,394,505,11,'IMPRESSAO',true);
        self::text($content,394,486,8,'Frente:',true,[.32,.38,.39]);
        $front = self::orderColourRows((string)($snapshot['of_front_colors']??'')); $cy=472;
        foreach ($front ?: [$dash] as $colour) { self::text($content,400,$cy,8,'- '.self::shorten($colour,27)); $cy-=13; }
        self::text($content,394,$cy-4,8,'Verso:',true,[.32,.38,.39]); $cy-=18;
        $back = self::orderColourRows((string)($snapshot['of_back_colors']??''));
        foreach ($back ?: [$dash] as $colour) { self::text($content,400,$cy,8,'- '.self::shorten($colour,27)); $cy-=13; }
        self::line($content,394,382,557,382,.5,[.7,.7,.7]);
        self::text($content,394,362,9,'ROLO IMPRESSOR',true);
        self::text($content,394,344,9,$roll);

        self::sectionBar($content,28,292,539,18,'REGISTO DE PRODUCAO');
        self::tableHeader($content,28,272,[48,222,155,114],['SEQ.','OPERACAO','MAQUINA','ESTADO']);
        $rowY=248;
        foreach (array_slice($operations,0,4) as $operation) {
            self::tableRow($content,28,$rowY,[48,222,155,114],[(string)($operation['sequence_no']??$dash),self::shorten($value($operation['name']??''),35),self::shorten($value($operation['machine_name']??''),23),$value($operation['status']??'')]);
            $rowY-=20;
        }
        if (!$operations) { self::tableRow($content,28,$rowY,[48,222,155,114],[$dash,$dash,$dash,$dash]); $rowY-=20; }

        $notesTop = min(190, $rowY - 8);
        self::sectionBar($content,28,$notesTop,539,18,'OBSERVACOES');
        self::rect($content,28,$notesTop-48,539,48,true,[1,1,1]);
        self::wrappedText($content,36,$notesTop-18,8,$value($order['notes']??$snapshot['_order']['notes']??''),86,3);
        self::line($content,28,35,567,35,.45,[.55,.55,.55]);
        self::text($content,28,22,6,'Documento: '.$documentNumber.'  |  OF: '.$orderNo.'  |  Gerado pelo GesTisser',false,[.35,.4,.4]);
        self::text($content,567,22,7,'TISSER',true,[.2,.25,.25],true);

        return self::document($content, $jpeg, $logo);
    }

    private static function line(string &$content, float $x1, float $y1, float $x2, float $y2, float $width = .7, array $colour = [0,0,0])
    { $content .= sprintf("%.3F %.3F %.3F RG\n%.2F w\n%.2F %.2F m %.2F %.2F l S\n",$colour[0],$colour[1],$colour[2],$width,$x1,$y1,$x2,$y2); }

    private static function rect(string &$content, float $x, float $y, float $w, float $h, bool $stroke = true, array $fill = [1,1,1])
    { $content .= sprintf("%.3F %.3F %.3F rg\n%.2F %.2F %.2F %.2F re f\n",$fill[0],$fill[1],$fill[2],$x,$y,$w,$h); if($stroke)$content .= sprintf(".55 .58 .58 RG\n.65 w\n%.2F %.2F %.2F %.2F re S\n",$x,$y,$w,$h); }

    private static function sectionBar(string &$content, float $x, float $y, float $w, float $h, string $title)
    { self::rect($content,$x,$y,$w,$h,true,[.93,.93,.93]); self::text($content,$x+$w/2,$y+5,9,$title,true,[0,0,0],true); }

    private static function fieldBox(string &$content, float $x, float $y, float $w, float $h, string $label, string $value, float $size, bool $highlight = false)
    { self::rect($content,$x,$y,$w,$h,true,$highlight?[.93,.97,.99]:[1,1,1]); self::text($content,$x+7,$y+$h-11,6,$label,true,[.32,.38,.39]); self::text($content,$x+7,$y+8,(int)$size,self::shorten($value,max(8,(int)($w/($size*.53)))),true); }

    private static function image(string &$content, string $name, string $jpeg, float $x, float $y, float $w, float $h)
    { $size=@getimagesizefromstring($jpeg);$sw=(int)($size[0]??1);$sh=(int)($size[1]??1);$scale=min($w/max(1,$sw),$h/max(1,$sh));$dw=$sw*$scale;$dh=$sh*$scale;$dx=$x+($w-$dw)/2;$dy=$y+($h-$dh)/2;$content.=sprintf("q\n%.2F 0 0 %.2F %.2F %.2F cm\n/%s Do\nQ\n",$dw,$dh,$dx,$dy,$name); }

    private static function wrappedText(string &$content, float $x, float $y, int $size, string $value, int $columns, int $maxLines)
    { $words=preg_split('/\s+/u',trim($value))?:[];$lines=[];$line='';foreach($words as$word){$next=$line===''?$word:$line.' '.$word;if(strlen($next)>$columns){$lines[]=$line;$line=$word;}else$line=$next;}if($line!=='')$lines[]=$line;foreach(array_slice($lines,0,$maxLines)as$i=>$row)self::text($content,$x,$y-$i*11,$size,$row); }

    private static function heading(string &$content, float $x, float $y, string $label)
    {
        self::text($content, $x, $y, 9, $label, true, [0, 0, 0]);
        $content .= sprintf("0 0 0 RG\n0.8 w\n%.2F %.2F m 563 %.2F l S\n", $x, $y - 7, $y - 7);
    }

    private static function tableHeader(string &$content, float $x, float $y, array $widths, array $values)
    {
        $content .= sprintf("0.90 0.90 0.90 rg\n%.2F %.2F 531 20 re f\n", $x, $y - 14);
        self::tableText($content, $x, $y - 7, $widths, $values, 7, true);
    }

    private static function barcode128(string &$content, float $x, float $y, string $value, float $maxWidth = 130, float $height = 18)
    {
        $patterns=['212222','222122','222221','121223','121322','131222','122213','122312','132212','221213','221312','231212','112232','122132','122231','113222','123122','123221','223211','221132','221231','213212','223112','312131','311222','321122','321221','312212','322112','322211','212123','212321','232121','111323','131123','131321','112313','132113','132311','211313','231113','231311','112133','112331','132131','113123','113321','133121','313121','211331','231131','213113','213311','213131','311123','311321','331121','312113','312311','332111','314111','221411','431111','111224','111422','121124','121421','141122','141221','112214','112412','122114','122411','142112','142211','241211','221114','413111','241112','134111','111242','121142','121241','114212','124112','124211','411212','421112','421211','212141','214121','412121','111143','111341','131141','114113','114311','411113','411311','113141','114131','311141','411131','211412','211214','211232','2331112'];
        $clean=preg_replace('/[^\x20-\x7E]/','',$value);$codes=[104];foreach(str_split($clean)as$char)$codes[]=ord($char)-32;$checksum=104;foreach(array_slice($codes,1)as$i=>$code)$checksum+=($i+1)*$code;$codes[]=$checksum%103;$codes[]=106;
        $modules=0;foreach($codes as$code)$modules+=array_sum(array_map('intval',str_split($patterns[$code])));$module=min(.75,$maxWidth/max(1,$modules));$cursor=$x;foreach($codes as$code){foreach(str_split($patterns[$code])as$i=>$width){$points=(int)$width*$module;if($i%2===0)$content.=sprintf("0 0 0 rg\n%.2F %.2F %.2F %.2F re f\n",$cursor,$y,$points,$height);$cursor+=$points;}}
        self::text($content,$x+$maxWidth/2,$y-8,6,$clean,true,[0,0,0],true);
    }

    private static function tableRow(string &$content, float $x, float $y, array $widths, array $values)
    {
        self::tableText($content, $x, $y, $widths, $values, 8, false);
        $content .= sprintf("0.86 0.90 0.88 RG\n%.2F %.2F m 563 %.2F l S\n", $x, $y - 7, $y - 7);
    }

    private static function tableText(string &$content, float $x, float $y, array $widths, array $values, int $size, bool $bold)
    {
        foreach ($values as $index => $value) { self::text($content, $x + 7, $y, $size, (string) $value, $bold); $x += $widths[$index]; }
    }

    private static function text(string &$content, float $x, float $y, int $size, string $value, bool $bold = false, array $colour = [0.09, .15, .12], bool $right = false)
    {
        $safe = self::escape(self::winAnsi($value));
        if ($right) $x -= strlen($safe) * $size * .52;
        $content .= sprintf("BT\n%.3F %.3F %.3F rg\n/%s %d Tf\n%.2F %.2F Td\n(%s) Tj\nET\n", $colour[0], $colour[1], $colour[2], $bold ? 'F2' : 'F1', $size, $x, $y, $safe);
    }

    private static function document(string $content, string $jpeg, string $logo = ''): string
    {
        $resources = '/Font << /F1 4 0 R /F2 5 0 R >>';
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
            "<< /Length " . strlen($content) . ">>\nstream\n{$content}\nendstream",
        ];
        $xObjects = [];
        if ($jpeg !== '') { $size=@getimagesizefromstring($jpeg);$width=(int)($size[0]??1);$height=(int)($size[1]??1);$objects[]="<< /Type /XObject /Subtype /Image /Width {$width} /Height {$height} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ".strlen($jpeg).">>\nstream\n{$jpeg}\nendstream";$xObjects[]='/Artwork '.count($objects).' 0 R'; }
        if ($logo !== '') { $size=@getimagesizefromstring($logo);$width=(int)($size[0]??1);$height=(int)($size[1]??1);$objects[]="<< /Type /XObject /Subtype /Image /Width {$width} /Height {$height} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ".strlen($logo).">>\nstream\n{$logo}\nendstream";$xObjects[]='/Logo '.count($objects).' 0 R'; }
        if ($xObjects) $resources .= ' /XObject << '.implode(' ',$xObjects).' >>';
        $objects[2] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << ' . $resources . ' >> /Contents 6 0 R >>';
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"; $offsets = [0];
        foreach ($objects as $number => $object) { $offsets[] = strlen($pdf); $pdf .= ($number + 1) . " 0 obj\n{$object}\nendobj\n"; }
        $count = count($objects) + 1; $xref = strlen($pdf); $pdf .= "xref\n0 {$count}\n0000000000 65535 f \n";
        for ($i = 1; $i < $count; $i++) $pdf .= sprintf('%010d 00000 n ', $offsets[$i]) . "\n";
        return $pdf . "trailer\n<< /Size {$count} /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    }

    private static function jpegFromDataUri(string $value): string
    {
        if (!preg_match('#^data:image/[^;]+;base64,#', $value, $match)) return '';
        $decoded = base64_decode(substr($value, strlen($match[0])), true);
        if (!is_string($decoded)) return '';
        if (substr($decoded, 0, 2) === "\xFF\xD8") return $decoded;
        if (!function_exists('imagecreatefromstring')) return '';
        $image = @imagecreatefromstring($decoded); if (!$image) return '';
        ob_start(); imagejpeg($image, null, 92); $jpeg = (string) ob_get_clean(); imagedestroy($image); return $jpeg;
    }

    private static function orderColourRows(string $value): array
    {
        $rows=preg_split('/\R/u',trim($value))?:[];
        return array_values(array_filter(array_map(function($row){$row=trim((string)$row);return preg_replace('/^\s*\S+\s+(?:—|–|-)\s+/u','',$row)?:$row;},$rows),'strlen'));
    }

    private static function shorten(string $value, int $length): string { return strlen($value) > $length ? substr($value, 0, $length - 3) . '...' : $value; }
    private static function escape(string $value): string { return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $value); }
    private static function winAnsi(string $value): string
    {
        if (function_exists('iconv')) { $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $value); if (is_string($converted)) return $converted; }
        return preg_replace('/[^\x20-\x7E]/', '', $value) ?: '';
    }

    private static function orderColours(string $value): string
    {
        $rows = preg_split('/\R/u', trim($value)) ?: [];
        $rows = array_map(function ($row) { return preg_replace('/^\s*\S+\s+(?:—|–|-)\s+/u', '', trim((string) $row)) ?: trim((string) $row); }, $rows);
        return implode(' / ', array_values(array_filter($rows, 'strlen')));
    }
}
