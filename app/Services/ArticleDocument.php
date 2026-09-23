<?php
declare(strict_types=1);

final class ArticleDocument
{
    /** Maximum size accepted for each document attached to an article (10 MiB). */
    public const MAX_UPLOAD_BYTES = 10 * 1024 * 1024;

    /**
     * Validate the size-related upload metadata before the file is persisted.
     *
     * PHP may discard an oversized temporary file, so checking only the size in
     * UploadService would turn that case into an ambiguous format error.
     */
    public static function validateUploadSize(array $file)
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        $size = (int) ($file['size'] ?? 0);

        if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            || $size > self::MAX_UPLOAD_BYTES) {
            throw new RuntimeException('Cada documento do artigo deve ter no máximo 10 MB.');
        }
    }

    public static function url(int $documentId): string
    {
        return 'erp.php?page=article_document&id=' . $documentId;
    }

    public static function thumbnailUrl(int $documentId): string
    {
        return 'erp.php?page=article_document_thumbnail&id=' . $documentId;
    }

    /** Prefer an uploaded image; only rasterise the PDF when no image exists. */
    public static function mainArtwork(array $documents)
    {
        $ranked = [];
        foreach ($documents as $position => $document) {
            if (!is_array($document)) continue;
            $kind = self::presentation((string) ($document['file_url'] ?? ''))['kind'];
            if (!in_array($kind, ['image', 'pdf'], true)) continue;
            $main = (string) ($document['document_type'] ?? '') === 'production_main';
            $rank = $kind === 'image' ? ($main ? 0 : 1) : ($main ? 2 : 3);
            $ranked[] = [$rank, (int) $position, $document];
        }
        usort($ranked, function (array $left, array $right): int {
            return $left[0] === $right[0] ? $left[1] <=> $right[1] : $left[0] <=> $right[0];
        });
        return $ranked ? $ranked[0][2] : null;
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
                // Try the command-line PDF renderers below. Some Imagick builds
                // deliberately disable PDF while Poppler remains available.
            }
        }

        if ($extension === 'pdf') {
            $blob = self::rasterisePdf($absolutePath, $maxWidth, $maxHeight);
            if ($blob !== '') return $blob;
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

    /** Rasterise page one with Poppler or Ghostscript (compatible with PHP 7). */
    private static function rasterisePdf(string $absolutePath, int $maxWidth, int $maxHeight): string
    {
        if (!function_exists('proc_open')) return '';
        $temporaryBase = tempnam(sys_get_temp_dir(), 'gt-artwork-');
        if ($temporaryBase === false) return '';
        @unlink($temporaryBase);
        $commands = [
            ['pdftoppm', '-f', '1', '-singlefile', '-jpeg', '-jpegopt', 'quality=90', '-scale-to-x', (string) $maxWidth, '-scale-to-y', (string) $maxHeight, $absolutePath, $temporaryBase],
            ['gs', '-q', '-dSAFER', '-dBATCH', '-dNOPAUSE', '-dFirstPage=1', '-dLastPage=1', '-sDEVICE=jpeg', '-dJPEGQ=90', '-r144', '-dPDFFitPage', '-g' . $maxWidth . 'x' . $maxHeight, '-sOutputFile=' . $temporaryBase . '.jpg', $absolutePath],
        ];
        foreach ($commands as $command) {
            $pipes = [];
            $escapedCommand = implode(' ', array_map('escapeshellarg', $command));
            $process = @proc_open($escapedCommand, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
            if (!is_resource($process)) continue;
            fclose($pipes[0]); stream_get_contents($pipes[1]); fclose($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[2]);
            $status = proc_close($process);
            $output = $temporaryBase . '.jpg';
            if ($status === 0 && is_file($output)) {
                $blob = (string) @file_get_contents($output); @unlink($output);
                if (substr($blob, 0, 2) === "\xFF\xD8") return $blob;
            }
            @unlink($output);
        }
        return '';
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
