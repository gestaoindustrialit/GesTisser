<?php
declare(strict_types=1);

final class ArticleDocument
{
    public static function url(int $documentId): string
    {
        return 'erp.php?page=article_document&id=' . $documentId;
    }

    public static function thumbnailUrl(int $documentId): string
    {
        return 'erp.php?page=article_document_thumbnail&id=' . $documentId;
    }

    /**
     * Create a printable first-page preview. Images are normalised with GD and
     * PDFs use Imagick when the server has the PDF delegate enabled.
     */
    public static function thumbnail(string $absolutePath, int $maxWidth = 1200, int $maxHeight = 900): string
    {
        if ($absolutePath === '' || !is_file($absolutePath)) return '';
        $extension = strtolower((string) pathinfo($absolutePath, PATHINFO_EXTENSION));
        $image = null;

        if ($extension === 'pdf' && class_exists('Imagick')) {
            try {
                $imagick = new Imagick();
                $imagick->setResolution(144, 144);
                $imagick->readImage($absolutePath . '[0]');
                $imagick->setImageBackgroundColor('white');
                $imagick->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
                $imagick->thumbnailImage($maxWidth, $maxHeight, true, true);
                $imagick->setImageFormat('jpeg');
                $imagick->setImageCompressionQuality(86);
                $blob = $imagick->getImageBlob();
                $imagick->clear();
                if (is_string($blob) && $blob !== '') return $blob;
            } catch (Throwable $exception) {
                // Return the neutral preview below instead of breaking a dossier.
            }
        } elseif (function_exists('imagecreatefromstring')) {
            $source = @file_get_contents($absolutePath);
            $image = is_string($source) ? @imagecreatefromstring($source) : false;
            if ($image) {
                $width = imagesx($image); $height = imagesy($image);
                $scale = min(1, $maxWidth / max(1, $width), $maxHeight / max(1, $height));
                $thumb = imagecreatetruecolor(max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));
                $white = imagecolorallocate($thumb, 255, 255, 255); imagefill($thumb, 0, 0, $white);
                imagecopyresampled($thumb, $image, 0, 0, 0, 0, imagesx($thumb), imagesy($thumb), $width, $height);
                ob_start(); imagejpeg($thumb, null, 86); $blob = (string) ob_get_clean();
                imagedestroy($thumb); imagedestroy($image);
                if ($blob !== '') return $blob;
            }
        }

        return self::placeholderThumbnail($extension === 'pdf' ? 'PDF' : 'DOCUMENTO');
    }

    private static function placeholderThumbnail(string $label): string
    {
        if (!function_exists('imagecreatetruecolor')) {
            return (string) base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAf/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABBQJ//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPwF//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPwF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQAGPwJ//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPyF//9oADAMBAAIAAwAAABD/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/EH//xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/EH//xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAE/EH//2Q==', true);
        }
        $image = imagecreatetruecolor(800, 520);
        $background = imagecolorallocate($image, 245, 247, 246);
        $border = imagecolorallocate($image, 8, 119, 93);
        $text = imagecolorallocate($image, 23, 37, 31);
        imagefill($image, 0, 0, $background);
        imagerectangle($image, 16, 16, 783, 503, $border);
        imagestring($image, 5, 345, 235, $label, $text);
        imagestring($image, 3, 252, 270, 'PRE-VISUALIZACAO INDISPONIVEL', $text);
        ob_start(); imagejpeg($image, null, 85); $blob = (string) ob_get_clean(); imagedestroy($image);
        return $blob;
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
