<?php
class PayloadBuilder
{
    public static function build(string $template, array $record, string $type = 'json')
    {
        $result = preg_replace_callback('/\{\{([a-zA-Z0-9_.]+)\}\}/', function ($m) use ($record) {
            $value=$record; foreach(explode('.', $m[1]) as $part) { if (!is_array($value)||!array_key_exists($part,$value)) return ''; $value=$value[$part]; }
            return is_scalar($value) ? (string)$value : '';
        }, $template);
        if ($type === 'json') { json_decode($result, true); if (json_last_error() !== JSON_ERROR_NONE) throw new InvalidArgumentException('JSON inválido: '.json_last_error_msg()); }
        return $result;
    }
    public static function transform($value, string $transform, string $type)
    {
        if ($transform==='trim') $value=trim((string)$value); elseif($transform==='uppercase') $value=strtoupper((string)$value); elseif($transform==='lowercase') $value=strtolower((string)$value); elseif($transform==='decimal') $value=(float)str_replace(',','.',(string)$value);
        if ($type==='integer') return (int)$value; if($type==='decimal') return (float)$value; if($type==='boolean') return filter_var($value,FILTER_VALIDATE_BOOLEAN); return (string)$value;
    }
}
