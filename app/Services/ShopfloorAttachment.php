<?php
declare(strict_types=1);

final class ShopfloorAttachment
{
    public static function detectExtension(string $path): ?string
    {
        $header = file_get_contents($path, false, null, 0, 1024);
        if (is_string($header) && strpos($header, '%PDF-') !== false) {
            return 'pdf';
        }

        $mimeType = self::detectMimeType($path);
        $imageExtensions = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];

        return $imageExtensions[$mimeType] ?? null;
    }

    private static function detectMimeType(string $path): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mimeType = finfo_file($finfo, $path);
                finfo_close($finfo);
                if (is_string($mimeType)) {
                    return $mimeType;
                }
            }
        }

        if (function_exists('mime_content_type')) {
            $mimeType = mime_content_type($path);
            if (is_string($mimeType)) {
                return $mimeType;
            }
        }

        return '';
    }
}
