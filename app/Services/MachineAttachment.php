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

/**
 * Build the application route used to serve an attachment.
 *
 * Use the ERP front controller because some installations only publish the
 * established application entry points. A separate PHP file can be handled by
 * the hosting fallback and return an unrelated HTML site inside the PDF frame.
 */
function gt_machine_attachment_url(array $attachment): string
{
    return 'erp.php?page=machine_attachment&id=' . rawurlencode((string) ((int) ($attachment['id'] ?? 0)));
}

/**
 * Resolve a stored machine path while preventing access outside its upload
 * directory. Returns an empty string for missing or unsafe paths.
 */
function gt_machine_attachment_path(string $applicationRoot, string $storedPath): string
{
    $prefix = 'storage/uploads/machines/';
    if (strncmp($storedPath, $prefix, strlen($prefix)) !== 0) {
        return '';
    }

    $uploadRoot = realpath(rtrim($applicationRoot, '/\\') . '/' . rtrim($prefix, '/'));
    $resolved = realpath(rtrim($applicationRoot, '/\\') . '/' . $storedPath);
    if ($uploadRoot === false || $resolved === false || !is_file($resolved)) {
        return '';
    }

    $uploadRoot .= DIRECTORY_SEPARATOR;
    return strncmp($resolved, $uploadRoot, strlen($uploadRoot)) === 0 ? $resolved : '';
}

/** Keep the visible name while removing characters that can change the path. */
function gt_machine_attachment_safe_name(string $name, string $fallback): string
{
    $name = preg_replace('/[\\x00-\\x1F\\x7F\\/\\\\]+/u', '_', trim($name));
    $name = trim((string) $name, " .\t\n\r\0\x0B");
    return $name !== '' && $name !== '.' && $name !== '..' ? $name : $fallback;
}

function gt_machine_attachment_directory_name(int $machineId, string $machineName): string
{
    return gt_machine_attachment_safe_name($machineName, 'maquina') . '__' . $machineId;
}

/** Return an unused path, preserving the original name whenever possible. */
function gt_machine_attachment_target(string $directory, string $originalName): string
{
    $fileName = gt_machine_attachment_safe_name($originalName, 'documento');
    $target = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $fileName;
    if (!file_exists($target)) return $target;

    $extension = pathinfo($fileName, PATHINFO_EXTENSION);
    $base = pathinfo($fileName, PATHINFO_FILENAME);
    for ($copy = 2; file_exists($target); $copy++) {
        $target = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $base . ' (' . $copy . ')'
            . ($extension !== '' ? '.' . $extension : '');
    }
    return $target;
}

/**
 * Move all existing documents to the folder derived from the current machine
 * name. Database paths are updated only after each file has moved successfully.
 */
function gt_machine_relocate_attachments(PDO $pdo, string $applicationRoot, int $machineId, string $machineName): void
{
    $relativeDirectory = 'storage/uploads/machines/' . gt_machine_attachment_directory_name($machineId, $machineName);
    $directory = rtrim($applicationRoot, '/\\') . '/' . $relativeDirectory;
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Não foi possível preparar a pasta de documentos da máquina.');
    }

    $stmt = $pdo->prepare('SELECT id, original_name, file_path FROM erp_machine_attachments WHERE machine_id=? AND deleted_at IS NULL');
    $stmt->execute([$machineId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $attachment) {
        $source = gt_machine_attachment_path($applicationRoot, (string) $attachment['file_path']);
        if ($source === '') continue;
        if (dirname($source) === realpath($directory)) continue;
        $target = gt_machine_attachment_target($directory, (string) $attachment['original_name']);
        if (!rename($source, $target)) {
            throw new RuntimeException('Não foi possível reorganizar os documentos da máquina.');
        }
        $newPath = $relativeDirectory . '/' . basename($target);
        $pdo->prepare('UPDATE erp_machine_attachments SET file_path=? WHERE id=?')->execute([$newPath, (int) $attachment['id']]);
        @rmdir(dirname($source));
    }
}
