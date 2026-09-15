<?php

class NumberSequenceService
{
    public static function format(array $sequence): string
    {
        return (string) $sequence['prefix']
            . str_pad((string) $sequence['next_number'], (int) $sequence['padding'], '0', STR_PAD_LEFT)
            . (string) ($sequence['suffix'] ?? '');
    }

    public static function peek(PDO $pdo, string $code): string
    {
        $stmt = $pdo->prepare('SELECT prefix,next_number,padding,suffix FROM erp_number_sequences WHERE code=?');
        $stmt->execute([$code]);
        $sequence = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$sequence) throw new RuntimeException('Sequência de numeração não configurada: '.$code.'.');
        return self::format($sequence);
    }

    /** Reserve the next number inside the transaction opened by the caller. */
    public static function take(PDO $pdo, string $code): string
    {
        $stmt = $pdo->prepare('SELECT id,prefix,next_number,padding,suffix FROM erp_number_sequences WHERE code=?');
        $stmt->execute([$code]);
        $sequence = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$sequence) throw new RuntimeException('Sequência de numeração não configurada: '.$code.'.');
        $number = self::format($sequence);
        $update = $pdo->prepare('UPDATE erp_number_sequences SET next_number=next_number+1,updated_at=CURRENT_TIMESTAMP WHERE id=? AND next_number=?');
        $update->execute([(int) $sequence['id'], (int) $sequence['next_number']]);
        if ($update->rowCount() !== 1) throw new RuntimeException('A numeração foi alterada por outro utilizador. Tente novamente.');
        return $number;
    }
}
