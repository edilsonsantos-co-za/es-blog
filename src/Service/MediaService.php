<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Handles file uploads and image thumbnail generation.
 *
 * Uploads land in %kernel.project_dir%/public/uploads/
 * Thumbnails are stored in public/uploads/thumbnails/ as WebP.
 *
 * The upload directory is configurable via the UPLOAD_DIR env var; this keeps
 * the public/ directory clean in Docker (mount a volume at /app/public/uploads).
 */
final class MediaService
{
    private string $uploadDir;
    private string $thumbDir;

    public function __construct(
        string $projectDir,
        private readonly SiteConfigService $config,
    ) {
        $this->uploadDir = $projectDir . '/public/uploads';
        $this->thumbDir  = $this->uploadDir . '/thumbnails';
    }

    /**
     * Move an uploaded file to the uploads directory and return the public path.
     * e.g. "/uploads/2024/01/my-image.jpg"
     */
    public function upload(UploadedFile $file, string $subdirectory = ''): string
    {
        $ext      = $file->guessExtension() ?? $file->getClientOriginalExtension();
        $filename = bin2hex(random_bytes(8)) . '.' . $ext;

        $targetDir = $this->uploadDir;
        if ($subdirectory !== '') {
            $targetDir .= '/' . trim($subdirectory, '/');
        }

        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $file->move($targetDir, $filename);

        $relative = '/uploads' . ($subdirectory !== '' ? '/' . trim($subdirectory, '/') : '');

        return $relative . '/' . $filename;
    }

    /**
     * Generate a WebP thumbnail for any supported image.
     * Returns the original path unchanged if GD is unavailable or on error.
     *
     * @param string $absoluteSrc  Absolute filesystem path to the source image
     * @param int|null $width      Desired width in pixels (default from site config)
     * @param int|null $height     Desired height (null = proportional)
     */
    public function thumbnail(string $absoluteSrc, ?int $width = null, ?int $height = null): string
    {
        if (!extension_loaded('gd') || !file_exists($absoluteSrc)) {
            return $absoluteSrc;
        }

        $width ??= $this->config->getInt('thumbnail.width', 500);

        if (!is_dir($this->thumbDir)) {
            mkdir($this->thumbDir, 0755, true);
        }

        $suffix    = $width . ($height !== null ? 'x' . $height : '');
        $filename  = pathinfo($absoluteSrc, PATHINFO_FILENAME);
        $thumbPath = $this->thumbDir . '/' . $filename . '-' . $suffix . '.webp';
        $thumbUrl  = '/uploads/thumbnails/' . $filename . '-' . $suffix . '.webp';

        if (file_exists($thumbPath)) {
            return $thumbUrl;
        }

        $source = @imagecreatefromstring(file_get_contents($absoluteSrc));
        if ($source === false) {
            return $absoluteSrc;
        }

        $srcW = imagesx($source);
        $srcH = imagesy($source);

        if ($height === null) {
            $height = (int) floor($srcH * ($width / $srcW));
        }

        // Cover-crop: scale to fill then centre-crop
        $ratio  = max($width / $srcW, $height / $srcH);
        $cropW  = (int) floor($width / $ratio);
        $cropH  = (int) floor($height / $ratio);
        $offsetX = (int) floor(($srcW - $cropW) / 2);
        $offsetY = (int) floor(($srcH - $cropH) / 2);

        $thumb = imagecreatetruecolor($width, $height);

        // Preserve alpha for PNG sources
        imagealphablending($thumb, false);
        imagesavealpha($thumb, true);

        imagecopyresampled($thumb, $source, 0, 0, $offsetX, $offsetY, $width, $height, $cropW, $cropH);

        imagewebp($thumb, $thumbPath, 82);
        imagedestroy($source);
        imagedestroy($thumb);

        return $thumbUrl;
    }

    /**
     * Return all uploaded image filenames in a given subdirectory.
     *
     * @return string[]
     */
    public function scanImages(string $subdirectory = ''): array
    {
        $dir = $this->uploadDir . ($subdirectory !== '' ? '/' . trim($subdirectory, '/') : '');
        if (!is_dir($dir)) {
            return [];
        }

        $files = array_filter(
            scandir($dir) ?: [],
            fn (string $f) => preg_match('/\.(jpg|jpeg|png|gif|webp|avif)$/i', $f) === 1,
        );

        return array_values($files);
    }

    public function getUploadDir(): string
    {
        return $this->uploadDir;
    }
}
