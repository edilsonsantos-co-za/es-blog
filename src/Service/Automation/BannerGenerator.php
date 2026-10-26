<?php

declare(strict_types=1);

namespace App\Service\Automation;

/**
 * Draws a 1200×630 Atlas-branded banner (blog header, Open Graph and LinkedIn image) with GD.
 */
final class BannerGenerator
{
    public const WIDTH = 1200;
    public const HEIGHT = 630;

    private string $fontDir;
    private string $markPath;
    private string $uploadDir;

    public function __construct(string $projectDir)
    {
        $this->fontDir = $projectDir . '/resources/fonts';
        $this->markPath = $projectDir . '/resources/brand/es-mark.png';
        $this->uploadDir = $projectDir . '/public/uploads';
    }

    /**
     * @return string public path, e.g. "/uploads/banners/2026/10/my-post.png"
     */
    public function generate(string $title, string $kicker, string $slug, ?string $credit = null): string
    {
        $subdir = 'banners/' . date('Y/m');
        $dir = $this->uploadDir . '/' . $subdir;
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create ' . $dir);
        }
        $file = preg_replace('/[^a-z0-9-]+/', '-', strtolower($slug)) ?: bin2hex(random_bytes(6));
        $file = trim(mb_substr($file, 0, 80), '-') . '.png';

        imagepng($this->render($title, $kicker, $credit), $dir . '/' . $file, 6);

        return '/uploads/' . $subdir . '/' . $file;
    }

    public function render(string $title, string $kicker, ?string $credit = null): \GdImage
    {
        $img = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        imagealphablending($img, true);
        imagesavealpha($img, true);

        $bg = $this->hex($img, '#0b1220');
        $blue = $this->hex($img, '#2563eb');
        $white = $this->hex($img, '#f8fafc');
        $muted = $this->hex($img, '#94a3b8');
        $line = $this->hex($img, '#1e293b');
        imagefilledrectangle($img, 0, 0, self::WIDTH, self::HEIGHT, $bg);

        // Soft blue glow in the top-right corner, drawn as stacked translucent circles.
        for ($r = 620; $r > 0; $r -= 20) {
            $alpha = (int) max(0, min(127, 127 - (620 - $r) / 620 * 22));
            imagefilledellipse($img, 1120, 40, $r * 2, $r * 2, imagecolorallocatealpha($img, 37, 99, 235, $alpha));
        }
        // Grid lines, a nod to the Atlas bento layout.
        for ($x = 80; $x < self::WIDTH; $x += 80) {
            imageline($img, $x, 0, $x, self::HEIGHT, imagecolorallocatealpha($img, 148, 163, 184, 120));
        }
        imagefilledrectangle($img, 0, self::HEIGHT - 8, self::WIDTH, self::HEIGHT, $blue);

        $bold = $this->fontDir . '/Manrope-Bold.ttf';
        $medium = $this->fontDir . '/Manrope-Medium.ttf';
        $mono = $this->fontDir . '/JetBrainsMono-Medium.ttf';

        if (is_file($this->markPath) && ($mark = imagecreatefrompng($this->markPath))) {
            imagecopyresampled($img, $mark, 72, 64, 0, 0, 64, 64, imagesx($mark), imagesy($mark));
        }
        imagettftext($img, 22, 0, 152, 108, $white, $bold, 'Edilson Santos');

        // Kicker chip
        $kicker = strtoupper(mb_substr($kicker, 0, 48));
        $box = imagettfbbox(15, 0, $mono, $kicker);
        $chipW = $box[2] - $box[0] + 36;
        imagefilledrectangle($img, 72, 180, 72 + $chipW, 220, imagecolorallocatealpha($img, 37, 99, 235, 80));
        imagettftext($img, 15, 0, 90, 207, $white, $mono, $kicker);

        // Title: largest size that fits in four lines.
        foreach ([60, 54, 48, 42, 38] as $size) {
            $lines = $this->wrap($title, $bold, $size, 1040);
            if (count($lines) <= 4) {
                break;
            }
        }
        $lines = array_slice($lines, 0, 4);
        $y = 290;
        foreach ($lines as $i => $text) {
            if ($i === 3 && count($this->wrap($title, $bold, $size, 1040)) > 4) {
                $text = rtrim($text, ' .,;:') . '…';
            }
            imagettftext($img, $size, 0, 72, $y, $white, $bold, $text);
            $y += (int) ($size * 1.28);
        }

        imageline($img, 72, 540, self::WIDTH - 72, 540, $line);
        imagettftext($img, 16, 0, 72, 580, $muted, $mono, 'BLOG.EDILSONSANTOS.CO.ZA');
        if ($credit) {
            $credit = 'via ' . mb_substr($credit, 0, 40);
            $cb = imagettfbbox(17, 0, $medium, $credit);
            imagettftext($img, 17, 0, self::WIDTH - 72 - ($cb[2] - $cb[0]), 580, $muted, $medium, $credit);
        }

        return $img;
    }

    /** @return list<string> */
    private function wrap(string $text, string $font, int $size, int $maxWidth): array
    {
        $lines = [];
        $current = '';
        foreach (preg_split('/\s+/u', trim($text)) ?: [] as $word) {
            $try = $current === '' ? $word : $current . ' ' . $word;
            $box = imagettfbbox($size, 0, $font, $try);
            if ($box[2] - $box[0] > $maxWidth && $current !== '') {
                $lines[] = $current;
                $current = $word;
            } else {
                $current = $try;
            }
        }
        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines;
    }

    private function hex(\GdImage $img, string $hex): int
    {
        [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');

        return imagecolorallocate($img, $r, $g, $b);
    }
}
