<?php
declare(strict_types=1);

/** Shared conversion for report logos rendered by the browser. */
final class ReportLogo
{
    public static function source(string $configuredPath, string $root): string
    {
        $configuredPath = trim($configuredPath);
        if ($configuredPath === '') return '';
        if (preg_match('#^data:image/[^;]+;base64,#', $configuredPath)) return $configuredPath;
        $remote=(bool)preg_match('#^https?://#i', $configuredPath);
        $root = realpath($root);
        if ($root === false) return '';
        $urlPath=parse_url($configuredPath,PHP_URL_PATH);
        $normalized=str_replace('\\','/',rawurldecode(is_string($urlPath)?$urlPath:$configuredPath));
        if($remote&&strpos($normalized,'assets/')===false&&strpos($normalized,'storage/uploads/')===false)return $configuredPath;
        $candidates=[$root.'/'.ltrim($normalized,'/'),$normalized];
        foreach(['assets/','storage/uploads/'] as $prefix){$offset=strpos($normalized,$prefix);if($offset!==false)$candidates[]=$root.'/'.substr($normalized,$offset);}
        $allowedRoots=[$root];foreach(['assets/uploads','storage/uploads'] as $directory){$resolved=realpath($root.'/'.$directory);if($resolved!==false)$allowedRoots[]=$resolved;}
        $path=false;
        foreach(array_unique($candidates) as $candidate){
            $resolved=realpath($candidate);if(!$resolved||!is_file($resolved)||!is_readable($resolved))continue;
            foreach($allowedRoots as $allowedRoot)if(strpos($resolved,$allowedRoot.DIRECTORY_SEPARATOR)===0){$path=$resolved;break 2;}
        }
        if($path===false)return $remote?$configuredPath:'';
        $types = ['png'=>'image/png', 'jpg'=>'image/jpeg', 'jpeg'=>'image/jpeg', 'webp'=>'image/webp', 'svg'=>'image/svg+xml'];
        $extension = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
        $contents = @file_get_contents($path);
        return $contents === false ? '' : 'data:' . ($types[$extension] ?? 'application/octet-stream') . ';base64,' . base64_encode($contents);
    }
}
