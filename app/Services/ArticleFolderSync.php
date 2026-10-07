<?php
declare(strict_types=1);

/**
 * Keeps the server-side folder of each article in sync with its documents.
 *
 * Administrators may copy supported files directly into an article folder.
 * Opening the Articles screen then registers files which are not yet present
 * in the document catalogue. Files are deliberately never deleted here.
 */
final class ArticleFolderSync
{
    const DOCUMENT_TYPE = 'Identificação do artigo';

    public static function sync(PDO $pdo, array $articles, int $userId): int
    {
        $insert = $pdo->prepare(
            'INSERT INTO erp_product_documents(entity_type,entity_id,document_type,title,file_url,author_user_id,status) '
            . 'VALUES ("finished_product",?,?,?,?,?,"Ativo")'
        );
        $find = $pdo->prepare(
            'SELECT 1 FROM erp_product_documents '
            . 'WHERE entity_type="finished_product" AND entity_id=? AND file_url=? LIMIT 1'
        );
        $imported = 0;

        foreach ($articles as $article) {
            $articleId = (int) ($article['id'] ?? 0);
            if ($articleId < 1) continue;
            $directory = self::directory($articleId);
            self::ensureDirectory($directory);

            $entries = scandir($directory);
            if (!is_array($entries)) continue;
            foreach ($entries as $fileName) {
                if ($fileName === '.' || $fileName === '..' || strpos($fileName, '.') === 0) continue;
                $absolutePath = $directory . '/' . $fileName;
                if (!is_file($absolutePath) || is_link($absolutePath)) continue;
                $size = (int) filesize($absolutePath);
                if ($size < 1 || $size > ArticleDocument::MAX_UPLOAD_BYTES) continue;
                $mime = UploadService::detectMime($absolutePath);
                if (!in_array($mime, ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'], true)) continue;

                $url = self::fileUrl($articleId, $fileName);
                $find->execute([$articleId, $url]);
                if ($find->fetchColumn()) continue;
                $title = trim((string) pathinfo($fileName, PATHINFO_FILENAME));
                $insert->execute([$articleId, self::DOCUMENT_TYPE, $title !== '' ? $title : 'Documento do artigo', $url, $userId ?: null]);
                $imported++;
            }
        }

        return $imported;
    }

    /**
     * Put artwork uploaded through GesTisser in the article's managed folder.
     *
     * Older uploads were stored directly in storage/uploads.  Keep that source
     * file as a safety copy and point the catalogue entry at the new copy.  A
     * document which already came from the article folder (for example through
     * cPanel) is deliberately left untouched.
     */
    public static function copyArtworkToFolder(PDO $pdo, int $articleId): bool
    {
        if ($articleId < 1) return false;
        $query = $pdo->prepare(
            'SELECT id,title,file_url FROM erp_product_documents '
            . 'WHERE entity_type="finished_product" AND entity_id=? '
            . 'AND document_type="production_main" AND status="Ativo" ORDER BY id DESC LIMIT 1'
        );
        $query->execute([$articleId]);
        $document = $query->fetch(PDO::FETCH_ASSOC);
        if (!$document) return false;

        $source = ArticleDocument::absolutePath((string) app_config('paths.root'), (string) $document['file_url']);
        // UploadService historically returned storage/uploads/<name> even when
        // the environment-specific upload directory was storage/uploads/production.
        if ($source === '') {
            $legacyName = basename(rawurldecode((string) (parse_url((string) $document['file_url'], PHP_URL_PATH) ?: '')));
            $legacyCandidate = rtrim((string) app_config('paths.uploads'), '/\\') . '/' . $legacyName;
            if ($legacyName !== '' && $legacyName !== '.' && $legacyName !== '..' && is_file($legacyCandidate)) {
                $source = $legacyCandidate;
            }
        }
        if ($source === '') return false;
        $directory = self::directory($articleId);
        self::ensureDirectory($directory);
        $managedDirectory = realpath($directory);
        if ($managedDirectory !== false && dirname($source) === $managedDirectory) return false;

        $extension = strtolower((string) pathinfo($source, PATHINFO_EXTENSION));
        $base = preg_replace('/[^A-Za-z0-9._-]+/', '-', trim((string) ($document['title'] ?? 'maquete')));
        $base = trim((string) $base, '.-_');
        if ($base === '') $base = 'maquete';
        $fileName = $base . '-' . (int) $document['id'] . ($extension !== '' ? '.' . $extension : '');
        $destination = $directory . '/' . $fileName;
        if (!is_file($destination)) {
            $temporary = $destination . '.tmp-' . bin2hex(random_bytes(4));
            if (!copy($source, $temporary) || !rename($temporary, $destination)) {
                @unlink($temporary);
                throw new RuntimeException('Não foi possível copiar a maquete para a pasta do artigo.');
            }
            @chmod($destination, 0640);
        }
        $url = self::fileUrl($articleId, $fileName);
        $pdo->prepare('UPDATE erp_product_documents SET file_url=? WHERE id=?')->execute([$url, (int) $document['id']]);
        return true;
    }

    public static function directory(int $articleId): string
    {
        if ($articleId < 1) throw new InvalidArgumentException('O artigo deve ser válido.');
        return rtrim((string) app_config('paths.uploads'), '/\\') . '/articles/' . $articleId;
    }

    public static function displayPath(int $articleId): string
    {
        $root = rtrim(str_replace('\\', '/', (string) app_config('paths.root')), '/');
        $directory = str_replace('\\', '/', self::directory($articleId));
        return strpos($directory, $root . '/') === 0 ? substr($directory, strlen($root) + 1) : $directory;
    }

    /** Remove a catalogue file only when it belongs to this managed folder. */
    public static function removeManagedFile(int $articleId, string $fileUrl): bool
    {
        $path = ArticleDocument::absolutePath((string) app_config('paths.root'), $fileUrl);
        $directory = realpath(self::directory($articleId));
        if ($path === '' || $directory === false || dirname($path) !== $directory) return false;
        return unlink($path);
    }

    public static function fileUrl(int $articleId, string $fileName): string
    {
        $uploads = str_replace('\\', '/', rtrim((string) app_config('paths.uploads'), '/\\'));
        $root = str_replace('\\', '/', rtrim((string) app_config('paths.root'), '/\\'));
        if (strpos($uploads, $root . '/') !== 0) {
            throw new RuntimeException('A pasta de uploads dos artigos deve estar dentro da aplicação.');
        }
        $relativeUploads = substr($uploads, strlen($root) + 1);
        return $relativeUploads . '/articles/' . $articleId . '/' . rawurlencode($fileName);
    }

    private static function ensureDirectory(string $directory)
    {
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('Não foi possível criar a pasta de documentos do artigo.');
        }
    }
}
