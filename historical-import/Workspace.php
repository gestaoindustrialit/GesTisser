<?php
/** Uploaded content is never stored beneath the document root. */
final class HistoricalWorkspace
{
    public static function directory()
    {
        if(empty($_SESSION['historical_workspace'])) $_SESSION['historical_workspace']=bin2hex(random_bytes(24));
        $dir=rtrim(sys_get_temp_dir(),'/').'/gestisser-history-'.$_SESSION['historical_workspace'];
        if(!is_dir($dir) && !mkdir($dir,0700,true)) throw new RuntimeException('Não foi possível preparar área privada.');
        return $dir;
    }
    public static function write($name,array $data)
    {
        if(!in_array($name,['batch','report','history','articles','article_report'],true)) throw new RuntimeException('Nome inválido.');
        $json=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if($json===false) throw new RuntimeException('Conteúdo não codificável.');
        $dir=self::directory();$temp=tempnam($dir,'write_');
        try { chmod($temp,0600); if(file_put_contents($temp,$json,LOCK_EX)===false || !rename($temp,$dir.'/'.$name.'.json')) throw new RuntimeException('Falha ao guardar lote.'); }
        finally { if(is_file($temp)) unlink($temp); }
    }
    public static function read($name)
    {
        if(!in_array($name,['batch','report','history','articles','article_report'],true)) throw new RuntimeException('Nome inválido.');
        $path=self::directory().'/'.$name.'.json';
        if(!is_file($path)) return [];
        $data=json_decode(file_get_contents($path),true);
        if(!is_array($data)) throw new RuntimeException('Lote guardado inválido.');
        return $data;
    }
    public static function resetReport() { @unlink(self::directory().'/report.json'); }
    public static function clear() { foreach(['batch','report','history','articles','article_report'] as $name) @unlink(self::directory().'/'.$name.'.json'); }
}
