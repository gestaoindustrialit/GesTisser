<?php
declare(strict_types=1);

/** Small dependency-free PDF fallback used when the optional mPDF package is absent. */
final class ProductionDossierPdf
{
    public static function render(array $d): string
    {
        $order = (array) ($d['order'] ?? []);
        $snapshot = (array) ($d['snapshot'] ?? []);
        $metrics = (array) ($d['metrics'] ?? []);
        $lines = [
            'DOSSIER DE PRODUCAO',
            'OF ' . (string) ($order['order_number'] ?? ''),
            (string) ($order['customer_name'] ?? ''),
            'Artigo: ' . (string) ($order['article_code'] ?? ''),
            'Descricao: ' . (string) ($order['article_description'] ?? $snapshot['description'] ?? ''),
            'Estado: ' . (string) ($order['status'] ?? ''),
            '', 'FICHA TECNICA',
        ];
        foreach (['material'=>'Material','composition'=>'Composicao','width'=>'Largura','length'=>'Comprimento','grammage'=>'Gramagem','seam_type'=>'Costura','perforation_type'=>'Perfuracao'] as $key => $label) {
            if (trim((string) ($snapshot[$key] ?? '')) !== '') $lines[] = $label . ': ' . (string) $snapshot[$key];
        }
        $lines[] = ''; $lines[] = 'PRODUCAO';
        $lines[] = 'Quantidade planeada: ' . (string) ($order['planned_quantity'] ?? '0');
        $lines[] = 'Quantidade boa: ' . (string) ($metrics['good'] ?? '0');
        $lines[] = 'Desperdicio: ' . (string) ($metrics['rejected'] ?? '0');
        $lines[] = ''; $lines[] = 'OPERACOES';
        foreach ((array) ($d['operations'] ?? []) as $operation) {
            $lines[] = (string) ($operation['sequence_no'] ?? '') . ' - ' . (string) ($operation['name'] ?? '') . ' [' . (string) ($operation['status'] ?? '') . ']';
        }
        return self::document($lines);
    }

    private static function document(array $lines): string
    {
        $content = "BT\n/F1 18 Tf\n50 790 Td\n";
        foreach ($lines as $index => $line) {
            $fontSize = $index === 0 ? 18 : 10;
            $safe = str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], self::ascii((string) $line));
            $content .= ($index ? "0 -18 Td\n" : '') . "/F1 {$fontSize} Tf\n({$safe}) Tj\n";
        }
        $content .= "ET";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            "<< /Length " . strlen($content) . " >>\nstream\n{$content}\nendstream",
        ];
        $pdf = "%PDF-1.4\n"; $offsets = [0];
        foreach ($objects as $number => $object) { $offsets[] = strlen($pdf); $pdf .= ($number + 1) . " 0 obj\n{$object}\nendobj\n"; }
        $xref = strlen($pdf); $pdf .= "xref\n0 6\n0000000000 65535 f \n";
        for ($i = 1; $i <= 5; $i++) $pdf .= sprintf('%010d 00000 n ', $offsets[$i]) . "\n";
        return $pdf . "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    }

    private static function ascii(string $value): string
    {
        if (function_exists('iconv')) { $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value); if (is_string($converted)) return $converted; }
        return preg_replace('/[^\x20-\x7E]/', '', $value) ?: '';
    }
}
