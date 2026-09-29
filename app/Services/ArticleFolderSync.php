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

                $url = self::publicUrl($articleId, $fileName);
                $find->execute([$articleId, $url]);
                if ($find->fetchColumn()) continue;
                $title = trim((string) pathinfo($fileName, PATHINFO_FILENAME));
                $insert->execute([$articleId, self::DOCUMENT_TYPE, $title !== '' ? $title : 'Documento do artigo', $url, $userId ?: null]);
                $imported++;
            }
        }

        return $imported;
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

    private static function publicUrl(int $articleId, string $fileName): string
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
