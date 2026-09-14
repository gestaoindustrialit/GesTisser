<?php
declare(strict_types=1);

final class OrderImportNormalizer
{
    private const UNITS = ['UN'=>'UN','UND'=>'UN','PCS'=>'UN','PC'=>'UN','KG'=>'KG','KGS'=>'KG','M'=>'M','MT'=>'M','ML'=>'M','ROL'=>'ROL','ROLO'=>'ROL','PALETE'=>'PALETE'];

    public static function number(string $value): ?float
    {
        $v = preg_replace('/[\s\x{00A0}]/u', '', trim($value));
        if ($v === '' || !preg_match('/^-?[0-9.,]+$/', $v)) return null;
        $comma = strrpos($v, ','); $dot = strrpos($v, '.');
        if ($comma !== false && $dot !== false) $v = $comma > $dot ? str_replace(',', '.', str_replace('.', '', $v)) : str_replace(',', '', $v);
        elseif ($comma !== false) $v = substr_count($v, ',') === 1 ? str_replace(',', '.', $v) : str_replace(',', '', $v);
        elseif ($dot !== false && (substr_count($v, '.') > 1 || strlen($v)-$dot-1 === 3)) $v = str_replace('.', '', $v);
        return is_numeric($v) ? (float) $v : null;
    }

    public static function date(string $value): ?string
    {
        $value=trim($value);$months=['janvier'=>'Jan','février'=>'Feb','fevrier'=>'Feb','mars'=>'Mar','avril'=>'Apr','mai'=>'May','juin'=>'Jun','juillet'=>'Jul','août'=>'Aug','aout'=>'Aug','septembre'=>'Sep','octobre'=>'Oct','novembre'=>'Nov','décembre'=>'Dec','decembre'=>'Dec'];$value=str_ireplace(array_keys($months),array_values($months),$value); $formats=['!d/m/Y','!d-m-Y','!Y-m-d','!d M Y','!j F Y'];
        foreach($formats as $format){$d=DateTimeImmutable::createFromFormat($format,$value);$errors=DateTimeImmutable::getLastErrors();if($d && ($errors===false||(!$errors['warning_count']&&!$errors['error_count'])))return $d->format('Y-m-d');}
        return null;
    }

    public static function unit(string $value): array
    {
        $original=trim($value); $key=strtoupper(rtrim($original,'.'));
        return ['unit_original'=>$original,'unit_normalized'=>self::UNITS[$key]??$key];
    }
}
