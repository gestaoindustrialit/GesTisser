<?php
declare(strict_types=1);

final class UploadService
{
    /**
     * Detect an upload from its contents without requiring ext-fileinfo.
     *
     * Some production PHP installations do not enable fileinfo.  The fallback
     * deliberately checks signatures (and not the browser supplied MIME type)
     * so that validation remains meaningful on those installations.
     */
    public static function detectMime(string $path): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $detected = finfo_file($finfo, $path);
                finfo_close($finfo);
                if (is_string($detected) && $detected !== '' && $detected !== 'application/octet-stream') {
                    return $detected;
                }
            }
        }

        if (function_exists('mime_content_type')) {
            $detected = mime_content_type($path);
            if (is_string($detected) && $detected !== '' && $detected !== 'application/octet-stream') {
                return $detected;
            }
        }

        $handle = fopen($path, 'rb');
        $header = $handle !== false ? (string) fread($handle, 32) : '';
        if ($handle !== false) {
            fclose($handle);
        }

        if (strncmp($header, '%PDF-', 5) === 0) return 'application/pdf';
        if (substr($header, 0, 3) === "\xFF\xD8\xFF") return 'image/jpeg';
        if (substr($header, 0, 8) === "\x89PNG\r\n\x1A\n") return 'image/png';
        if (substr($header, 0, 6) === 'GIF87a' || substr($header, 0, 6) === 'GIF89a') return 'image/gif';
        if (substr($header, 0, 4) === 'RIFF' && substr($header, 8, 4) === 'WEBP') return 'image/webp';
        if (substr($header, 4, 4) === 'ftyp') return 'video/mp4';
        if (substr($header, 0, 4) === "\x1A\x45\xDF\xA3") return 'video/webm';
        if (substr($header, 0, 4) === 'OggS') return 'video/ogg';

        return '';
    }

    public static function secureUpload(array $file, array $allowedMimeTypes, int $maxBytes = 5242880)
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return null;
        }
        if ((int) ($file['size'] ?? 0) <= 0 || (int) ($file['size'] ?? 0) > $maxBytes) {
            return null;
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            return null;
        }

        $mime = self::detectMime($tmpName);

        if (!in_array($mime, $allowedMimeTypes, true)) {
            return null;
        }

        $ext = pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION);
        $safeName = bin2hex(random_bytes(12)) . ($ext !== '' ? '.' . strtolower($ext) : '');
        $uploadDir = app_config('paths.uploads');
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0750, true);
        }

        $targetPath = rtrim((string) $uploadDir, '/') . '/' . $safeName;
        if (!move_uploaded_file($tmpName, $targetPath)) {
            return null;
        }

        return 'storage/uploads/' . $safeName;
    }
}
