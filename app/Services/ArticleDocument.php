<?php
declare(strict_types=1);

final class ArticleDocument
{
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
