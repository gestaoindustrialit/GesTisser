<?php
declare(strict_types=1);

/**
 * Receive article documents in small requests, like machine attachments do.
 * This avoids depending on the host's (often 2 MB) PHP upload limit while the
 * application continues to enforce its own 20 MiB document policy.
 */
final class ArticleDocumentChunkUpload
{
    const CHUNK_BYTES = 512 * 1024;
    const MAX_CHUNKS = 40;

    public static function receive(int $userId, array $request, array $file): bool
    {
        $uploadId = (string) ($request['upload_id'] ?? '');
        $index = (int) ($request['chunk_index'] ?? -1);
        $total = (int) ($request['chunk_total'] ?? 0);
        $name = basename((string) ($request['file_name'] ?? 'documento'));
        if ($userId < 1 || !preg_match('/^[a-f0-9]{32}$/', $uploadId)
            || $index < 0 || $total < 1 || $index >= $total || $total > self::MAX_CHUNKS) {
            throw new RuntimeException('Pedido de upload inválido.');
        }
        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            throw new RuntimeException('O servidor rejeitou uma parte do documento. Tente novamente.');
        }
        $chunkSize = (int) ($file['size'] ?? 0);
        if ($chunkSize < 1 || $chunkSize > self::CHUNK_BYTES + 65536) {
            throw new RuntimeException('Uma parte do documento excede o limite permitido.');
        }

        $directory = self::directory($userId, $uploadId);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Não foi possível preparar o upload do documento.');
        }
        $metadataPath = $directory . '/meta.json';
        $existing = is_file($metadataPath) ? json_decode((string) file_get_contents($metadataPath), true) : null;
        if (is_array($existing) && ((int) ($existing['total'] ?? 0) !== $total || (string) ($existing['name'] ?? '') !== $name)) {
            self::removeDirectory($directory);
            throw new RuntimeException('Os dados do upload mudaram durante o envio. Tente novamente.');
        }
        if (!move_uploaded_file((string) $file['tmp_name'], $directory . '/' . $index . '.part')) {
            throw new RuntimeException('Não foi possível guardar uma parte do documento.');
        }
        file_put_contents($metadataPath, json_encode(['name' => $name, 'total' => $total, 'created' => time()]));
        if ($index + 1 < $total) return false;

        $complete = $directory . '/complete';
        $output = fopen($complete, 'wb');
        if ($output === false) throw new RuntimeException('Não foi possível concluir o upload do documento.');
        for ($partIndex = 0; $partIndex < $total; $partIndex++) {
            $part = $directory . '/' . $partIndex . '.part';
            $input = is_file($part) ? fopen($part, 'rb') : false;
            if ($input === false) { fclose($output); throw new RuntimeException('Falta uma parte do documento. Tente novamente.'); }
            stream_copy_to_stream($input, $output);
            fclose($input);
            unlink($part);
        }
        fclose($output);
        $size = (int) filesize($complete);
        if ($size < 1 || $size > ArticleDocument::MAX_UPLOAD_BYTES) {
            self::removeDirectory($directory);
            throw new RuntimeException('Cada documento do artigo deve ter no máximo 20 MB.');
        }
        $mime = UploadService::detectMime($complete);
        if (!in_array($mime, ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'], true)) {
            self::removeDirectory($directory);
            throw new RuntimeException('Utilize apenas documentos PDF, JPG, PNG ou WEBP.');
        }
        return true;
    }

    public static function persist(int $userId, string $uploadId): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $uploadId)) throw new RuntimeException('Documento temporário inválido.');
        $directory = self::directory($userId, $uploadId);
        $complete = $directory . '/complete';
        $metadata = is_file($directory . '/meta.json') ? json_decode((string) file_get_contents($directory . '/meta.json'), true) : null;
        if (!is_array($metadata) || !is_file($complete) || (int) ($metadata['created'] ?? 0) < time() - 3600) {
            self::removeDirectory($directory);
            throw new RuntimeException('O upload temporário expirou. Selecione novamente o documento.');
        }
        $name = basename((string) ($metadata['name'] ?? 'documento'));
        $mime = UploadService::detectMime($complete);
        $extensions = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!isset($extensions[$mime])) {
            self::removeDirectory($directory);
            throw new RuntimeException('Utilize apenas documentos PDF, JPG, PNG ou WEBP.');
        }
        $safeName = bin2hex(random_bytes(12)) . '.' . $extensions[$mime];
        $uploadDirectory = (string) app_config('paths.uploads');
        if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0750, true) && !is_dir($uploadDirectory)) {
            throw new RuntimeException('Não foi possível preparar a pasta de documentos.');
        }
        if (!rename($complete, rtrim($uploadDirectory, '/\\') . '/' . $safeName)) {
            throw new RuntimeException('Não foi possível guardar o documento.');
        }
        self::removeDirectory($directory);
        return ['name' => $name, 'url' => 'storage/uploads/' . $safeName];
    }

    private static function directory(int $userId, string $uploadId): string
    {
        return rtrim(sys_get_temp_dir(), '/\\') . '/gestisser_article_' . $userId . '_' . $uploadId;
    }

    private static function removeDirectory(string $directory)
    {
        if (!is_dir($directory)) return;
        foreach ((array) glob($directory . '/*') as $path) if (is_file($path)) @unlink($path);
        @rmdir($directory);
    }
}
