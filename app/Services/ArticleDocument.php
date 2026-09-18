<?php
declare(strict_types=1);

final class ArticleDocument
{
    public static function url(int $documentId): string
    {
        return 'erp.php?page=article_document&id=' . $documentId;
    }

    /** Resolve legacy upload URLs while preventing reads outside storage/uploads. */
    public static function absolutePath(string $root, string $fileUrl): string
    {
        $urlPath = rawurldecode((string) (parse_url($fileUrl, PHP_URL_PATH) ?: $fileUrl));
        $relativePath = ltrim(str_replace('\\', '/', $urlPath), '/');
        if (strpos($relativePath, 'storage/uploads/') !== 0 || strpos($relativePath, "\0") !== false) {
            return '';
        }

        $uploadRoot = realpath(rtrim($root, '/\\') . '/storage/uploads');
        $candidate = realpath(rtrim($root, '/\\') . '/' . $relativePath);
        if ($uploadRoot === false || $candidate === false || !is_file($candidate)) return '';

        $prefix = rtrim(str_replace('\\', '/', $uploadRoot), '/') . '/';
        $normalisedCandidate = str_replace('\\', '/', $candidate);
        return strpos($normalisedCandidate, $prefix) === 0 ? $candidate : '';
    }

    /** Return the presentation data used for an article attachment. */
    public static function presentation(string $fileUrl): array
    {
        $path = (string) (parse_url($fileUrl, PHP_URL_PATH) ?: $fileUrl);
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        if ($extension === 'pdf') {
            return ['kind' => 'pdf', 'label' => 'PDF', 'icon' => 'bi-file-earmark-pdf', 'class' => 'text-danger'];
        }
        if (in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return [
                'kind' => 'image',
                'label' => $extension === 'jpeg' ? 'JPG' : strtoupper($extension),
                'icon' => 'bi-file-earmark-image',
                'class' => 'text-primary',
            ];
        }

        return ['kind' => 'document', 'label' => 'DOC', 'icon' => 'bi-file-earmark-text', 'class' => 'text-secondary'];
    }
}
