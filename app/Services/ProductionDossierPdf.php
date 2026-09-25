<?php
declare(strict_types=1);

/** Designed, dependency-free A4 dossier used when the optional mPDF package is absent. */
final class ProductionDossierPdf
{
    public static function render(array $d, string $artworkDataUri = '', string $documentNumber = 'DOC-PRD-001'): string
    {
        $order = (array) ($d['order'] ?? []);
        $snapshot = (array) ($d['snapshot'] ?? []);
        $metrics = (array) ($d['metrics'] ?? []);
        $operations = (array) ($d['operations'] ?? []);
        $jpeg = self::jpegFromDataUri($artworkDataUri);

        $content = "q\n0 0 0 RG\n1 w\n32 786 126 40 re S\nQ\n";
        self::text($content, 43, 799, 20, 'TISSER', true, [0, 0, 0]);
        self::text($content, 297, 809, 15, 'FOLHA DE ACOMPANHAMENTO', true, [.05, .05, .05], true);
        self::text($content, 297, 792, 11, 'OF ' . (string) ($order['order_number'] ?? ''), true, [.05, .05, .05], true);
        self::text($content, 563, 811, 9, 'Tisser', true, [.05, .05, .05], true);
        self::text($content, 563, 795, 7, date('d/m/Y'), true, [.05, .05, .05], true);
        self::barcode128($content, 430, 765, (string) ($order['order_number'] ?? ''));
        $content .= "0 0 0 RG\n1.5 w\n32 780 m 563 780 l S\n";

        self::heading($content, 32, 764, 'DADOS PRINCIPAIS DA ENCOMENDA');
        $imageX = 32; $imageY = 535; $imageW = 250; $imageH = 205;
        $content .= "0.965 0.976 0.973 rg\n{$imageX} {$imageY} {$imageW} {$imageH} re f\n";
        if ($jpeg !== '') {
            $size = @getimagesizefromstring($jpeg);
            $sourceW = (int) ($size[0] ?? 1); $sourceH = (int) ($size[1] ?? 1);
            $scale = min(($imageW - 12) / max(1, $sourceW), ($imageH - 12) / max(1, $sourceH));
            $drawW = $sourceW * $scale; $drawH = $sourceH * $scale;
            $drawX = $imageX + ($imageW - $drawW) / 2; $drawY = $imageY + ($imageH - $drawH) / 2;
            $content .= sprintf("q\n%.2F 0 0 %.2F %.2F %.2F cm\n/Artwork Do\nQ\n", $drawW, $drawH, $drawX, $drawY);
        } else {
            self::text($content, 157, 638, 9, 'SEM MAQUETA DISPONIVEL', true, [.40, .48, .45], true);
        }

        $details = [
            'Cliente' => $order['customer_name'] ?? $snapshot['customer_name'] ?? '',
            'Artigo' => $order['article_code'] ?? $snapshot['code'] ?? '',
            'Descricao' => $order['article_description'] ?? $snapshot['description'] ?? '',
            'Material' => $snapshot['material'] ?? '', 'Composicao' => $snapshot['composition'] ?? '',
            'Dimensoes' => trim((string) ($snapshot['width'] ?? '') . ' x ' . (string) ($snapshot['length'] ?? ''), ' x'),
            'Gramagem' => $snapshot['grammage'] ?? '', 'Rolo impressor' => $snapshot['printer_roll_measure'] ?? '',
            'Quantidade' => $order['planned_quantity'] ?? '',
            'Entrega' => $order['due_date'] ?? '',
            'Encomenda' => $order['customer_order_reference'] ?? $snapshot['_order']['customer_order'] ?? '',
            'N. prova' => $snapshot['_order']['proof_number'] ?? '',
            'N. paletes previsto' => $snapshot['_order']['planned_pallets'] ?? '',
            'Tipo de palete' => $snapshot['_order']['pallet_type'] ?? '',
            'Cor do fio' => $snapshot['thread_color'] ?? '', 'Costura' => $snapshot['seam_type'] ?? '',
        ];
        $y = 729;
        foreach ($details as $label => $value) {
            if (trim((string) $value) === '') continue;
            self::text($content, 302, $y, 7, strtoupper($label), true, [.40, .48, .45]);
            self::text($content, 302, $y - 12, 10, self::shorten((string) $value, 46), true);
            $y -= 31;
        }

        self::heading($content, 32, 507, 'REGISTO DE PRODUCAO');
        self::tableHeader($content, 32, 482, [45, 235, 125, 126], ['SEQ.', 'OPERACAO', 'MAQUINA', 'ESTADO']);
        $y = 461;
        foreach (array_slice($operations, 0, 7) as $operation) {
            self::tableRow($content, 32, $y, [45, 235, 125, 126], [
                (string) ($operation['sequence_no'] ?? ''), self::shorten((string) ($operation['name'] ?? ''), 36),
                self::shorten((string) ($operation['machine_name'] ?? '-'), 18), (string) ($operation['status'] ?? ''),
            ]);
            $y -= 22;
        }
        if (!$operations) { self::text($content, 40, 457, 9, 'Esta OF ainda nao tem operacoes no routing.', false, [.40, .48, .45]); $y -= 22; }

        $summaryY = min($y - 12, 285);
        self::heading($content, 32, $summaryY - 85, 'MAQUETA DO ARTIGO / REFERENCIA VISUAL');
        $front = self::orderColours((string) ($snapshot['of_front_colors'] ?? ''));
        $back = self::orderColours((string) ($snapshot['of_back_colors'] ?? ''));
        self::text($content, 32, $summaryY - 104, 8, 'FRENTE: ' . ($front ?: '-'), true);
        self::text($content, 302, $summaryY - 104, 8, 'VERSO: ' . ($back ?: '-'), true);
        self::heading($content, 32, $summaryY - 126, 'OBSERVACOES');
        self::text($content, 32, $summaryY - 145, 9, self::shorten((string) ($order['notes'] ?? 'Sem observacoes.'), 92));
        self::text($content, 32, 22, 7, 'Documento: ' . $documentNumber . '  |  OF: ' . (string) ($order['order_number'] ?? '') . '  |  Gerado pelo GesTisser', false, [.40, .48, .45]);

        return self::document($content, $jpeg);
    }

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

    private static function barcode128(string &$content, float $x, float $y, string $value)
    {
        $patterns=['212222','222122','222221','121223','121322','131222','122213','122312','132212','221213','221312','231212','112232','122132','122231','113222','123122','123221','223211','221132','221231','213212','223112','312131','311222','321122','321221','312212','322112','322211','212123','212321','232121','111323','131123','131321','112313','132113','132311','211313','231113','231311','112133','112331','132131','113123','113321','133121','313121','211331','231131','213113','213311','213131','311123','311321','331121','312113','312311','332111','314111','221411','431111','111224','111422','121124','121421','141122','141221','112214','112412','122114','122411','142112','142211','241211','221114','413111','241112','134111','111242','121142','121241','114212','124112','124211','411212','421112','421211','212141','214121','412121','111143','111341','131141','114113','114311','411113','411311','113141','114131','311141','411131','211412','211214','211232','2331112'];
        $clean=preg_replace('/[^\x20-\x7E]/','',$value);$codes=[104];foreach(str_split($clean)as$char)$codes[]=ord($char)-32;$checksum=104;foreach(array_slice($codes,1)as$i=>$code)$checksum+=($i+1)*$code;$codes[]=$checksum%103;$codes[]=106;
        $module=.55;$cursor=$x;foreach($codes as$code){foreach(str_split($patterns[$code])as$i=>$width){$points=(int)$width*$module;if($i%2===0)$content.=sprintf("0 0 0 rg\n%.2F %.2F %.2F 18 re f\n",$cursor,$y,$points);$cursor+=$points;}}
        self::text($content,$x,$y-9,6,$clean,true,[0,0,0]);
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

    private static function document(string $content, string $jpeg): string
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
        if ($jpeg !== '') {
            $size = @getimagesizefromstring($jpeg); $width = (int) ($size[0] ?? 1); $height = (int) ($size[1] ?? 1);
            $resources .= ' /XObject << /Artwork 7 0 R >>';
            $objects[] = "<< /Type /XObject /Subtype /Image /Width {$width} /Height {$height} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($jpeg) . ">>\nstream\n{$jpeg}\nendstream";
        }
        $objects[2] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << ' . $resources . ' >> /Contents 6 0 R >>';
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"; $offsets = [0];
        foreach ($objects as $number => $object) { $offsets[] = strlen($pdf); $pdf .= ($number + 1) . " 0 obj\n{$object}\nendobj\n"; }
        $count = count($objects) + 1; $xref = strlen($pdf); $pdf .= "xref\n0 {$count}\n0000000000 65535 f \n";
        for ($i = 1; $i < $count; $i++) $pdf .= sprintf('%010d 00000 n ', $offsets[$i]) . "\n";
        return $pdf . "trailer\n<< /Size {$count} /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    }

    private static function jpegFromDataUri(string $value): string
    {
        if (strpos($value, 'data:image/jpeg;base64,') !== 0) return '';
        $decoded = base64_decode(substr($value, 23), true);
        return is_string($decoded) && substr($decoded, 0, 2) === "\xFF\xD8" ? $decoded : '';
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
