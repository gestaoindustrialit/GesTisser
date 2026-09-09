<?php

/**
 * Detect the MIME type of a machine attachment without requiring ext-fileinfo.
 *
 * finfo is preferred when the host provides it.  The signature fallback keeps
 * uploads working on reduced PHP installations while still checking the file
 * contents instead of trusting the MIME type supplied by the browser.
 */
function gt_machine_attachment_mime(string $path, string $originalName): string
{
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $mime = finfo_file($finfo, $path);
            finfo_close($finfo);
            if (is_string($mime) && $mime !== '' && $mime !== 'application/octet-stream' && $mime !== 'application/zip') {
                return $mime;
            }
        }
    }

    if (function_exists('mime_content_type')) {
        $mime = mime_content_type($path);
        if (is_string($mime) && $mime !== '' && $mime !== 'application/octet-stream' && $mime !== 'application/zip') {
            return $mime;
        }
    }

    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $handle = fopen($path, 'rb');
    $header = $handle !== false ? (string) fread($handle, 8192) : '';
    if ($handle !== false) {
        fclose($handle);
    }

    if ($extension === 'pdf' && strncmp($header, '%PDF-', 5) === 0) return 'application/pdf';
    if (($extension === 'jpg' || $extension === 'jpeg') && substr($header, 0, 3) === "\xFF\xD8\xFF") return 'image/jpeg';
    if ($extension === 'png' && substr($header, 0, 8) === "\x89PNG\r\n\x1A\n") return 'image/png';
    if ($extension === 'webp' && substr($header, 0, 4) === 'RIFF' && substr($header, 8, 4) === 'WEBP') return 'image/webp';
    if (($extension === 'doc' || $extension === 'xls') && substr($header, 0, 8) === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
        return $extension === 'doc' ? 'application/msword' : 'application/vnd.ms-excel';
    }
    if (($extension === 'docx' || $extension === 'xlsx') && substr($header, 0, 2) === 'PK') {
        return $extension === 'docx'
            ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    }
    if (($extension === 'txt' || $extension === 'csv') && $header !== '' && strpos($header, "\0") === false) {
        return $extension === 'csv' ? 'text/csv' : 'text/plain';
    }

    return '';
}
