<?php
declare(strict_types=1);

final class ArticleTheoreticalWeight
{
    /**
     * Calculates grams per article from dimensions in centimetres and grammage in g/m².
     * Three centimetres are added to the article length for the production allowance.
     */
    public static function grams($width, $length, $grammage)
    {
        $width = self::number($width);
        $length = self::number($length);
        $grammage = self::totalGrammage($grammage);
        if ($width === null || $length === null || $grammage === null) {
            return null;
        }

        return round((($width * 2) * ($length + 3) * $grammage) / 10000);
    }

    private static function totalGrammage($value)
    {
        $parts = preg_split('/\+/', str_replace(',', '.', preg_replace('/\s+/', '', trim((string) $value))));
        if (!$parts || in_array('', $parts, true)) {
            return null;
        }

        $total = 0.0;
        foreach ($parts as $part) {
            if (!is_numeric($part) || (float) $part < 0) {
                return null;
            }
            $total += (float) $part;
        }
        return $total;
    }

    private static function number($value)
    {
        $value = str_replace(',', '.', trim((string) $value));
        if ($value === '' || !is_numeric($value) || (float) $value < 0) {
            return null;
        }
        return (float) $value;
    }
}
