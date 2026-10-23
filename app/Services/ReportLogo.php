<?php
declare(strict_types=1);

/** Shared conversion for report logos rendered by the browser. */
final class ReportLogo
{
    public static function source(string $configuredPath, string $root): string
    {
        $configuredPath = trim($configuredPath);
        if ($configuredPath === '') return '';
        if (preg_match('#^https?://#i', $configuredPath)) return $configuredPath;
        $root = realpath($root);
        if ($root === false) return '';
        $path = realpath($root . '/' . ltrim($configuredPath, '/'));
        if ($path === false || !is_file($path) || strpos($path, $root . DIRECTORY_SEPARATOR) !== 0) return '';
        $types = ['png'=>'image/png', 'jpg'=>'image/jpeg', 'jpeg'=>'image/jpeg', 'webp'=>'image/webp', 'svg'=>'image/svg+xml'];
        $extension = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
        $contents = @file_get_contents($path);
        return $contents === false ? '' : 'data:' . ($types[$extension] ?? 'application/octet-stream') . ';base64,' . base64_encode($contents);
    }
}
