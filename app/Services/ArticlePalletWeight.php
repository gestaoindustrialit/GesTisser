<?php
declare(strict_types=1);

final class ArticlePalletWeight
{
    /**
     * The article theoretical weight is stored in grams; pallet weight is shown in kilograms.
     */
    public static function kilograms($theoreticalWeight, $palletQuantity): ?float
    {
        $weight = self::number($theoreticalWeight);
        $quantity = self::number($palletQuantity);
        if ($weight === null || $quantity === null) {
            return null;
        }

        return round(($weight * $quantity) / 1000, 3);
    }

    private static function number($value): ?float
    {
        $value = str_replace(',', '.', trim((string) $value));
        if ($value === '' || !is_numeric($value)) {
            return null;
        }

        $number = (float) $value;
        return $number >= 0 ? $number : null;
    }
}
