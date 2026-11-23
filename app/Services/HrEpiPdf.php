<?php

final class HrEpiPdf
{
    public static function create(array $rows, $operation = null)
    {
        $pdf = new FPDF('P', 'mm', 'A4');
        $pdf->SetMargins(15, 15);
        $pdf->SetAutoPageBreak(true, 15);
        $pdf->AddPage();
        $title = $operation ? 'TISSER, LDA. — Comprovativo de Entrega de EPI' : 'TISSER, LDA. — Histórico individual de EPI';
        $pdf->SetTitle($title, true);
        self::line($pdf, $title, true);
        $employee = $operation ?: ($rows[0] ?? []);
        if ($employee) {
            self::line($pdf, trim($employee['employee_number'] . ' · ' . $employee['employee_name'], ' ·'));
        }
        if ($operation) {
            self::line($pdf, 'Entrega: ' . self::date($operation['delivered_at']) . ' · Responsável: ' . $operation['actor_name']);
            self::line($pdf, 'Comprovativo: ' . $operation['proof_id']);
        }
        $pdf->Ln(4);
        foreach ($rows as $row) {
            $label = $row['name'] . ' · Quantidade: ' . $row['quantity'] . ' · ' . $row['reason'];
            if (!$operation) {
                $label = self::date($row['delivered_at']) . ' · ' . $label;
            }
            self::line($pdf, $label, true);
            self::line($pdf, 'Referência: ' . ($row['reference'] ?: '—') . ' · Tamanho: ' . ($row['size'] ?: '—'));
            if (!$operation) {
                self::line($pdf, ($row['confirmed_at'] ? 'Confirmado · ' . $row['proof_id'] : 'Pendente de confirmação')
                    . ' · Quantidade devolvida: ' . $row['returned']);
                if ($row['replacement_date'] && $row['current_cycle'] && $row['returned'] < $row['quantity']) {
                    self::line($pdf, 'Substituição prevista: ' . self::date($row['replacement_date'], 'd/m/Y'));
                }
            }
            $pdf->Ln(2);
        }
        if (!$rows) {
            self::line($pdf, 'Sem entregas no período selecionado.');
        }
        if ($operation) {
            $pdf->Ln(4);
            self::line($pdf, 'Declaração de receção · Versão ' . $operation['declaration_version'], true);
            self::line($pdf, $operation['declaration']);
            self::line($pdf, 'Confirmação: ' . $operation['confirmation_method'] . ' · ' . self::date($operation['confirmed_at']));
            $pdf->Ln(4);
            self::line($pdf, 'Este comprovativo documenta o registo de confirmação eletrónica efetuado no sistema GesTISSER.');
        }
        return $pdf->Output('S');
    }

    public static function date($value, $format = 'd/m/Y H:i')
    {
        return (new DateTimeImmutable($value, new DateTimeZone('Europe/Lisbon')))->format($format);
    }

    private static function line(FPDF $pdf, $text, $bold = false)
    {
        $pdf->SetFont('Arial', $bold ? 'B' : '', $bold ? 12 : 10);
        $pdf->MultiCell(0, 6, iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text));
    }
}
