<?php

declare(strict_types=1);

namespace App\Service\Automation;

/**
 * Draws a 1200×630 banner (blog header, Open Graph and LinkedIn image) with GD. The background is an AI illustration
 * when AiBannerArt is configured, otherwise generative art whose palette and pattern come from the post itself, so
 * no two posts share a banner. Title, kicker and branding are always drawn on top.
 */
final class BannerGenerator
{
    public const WIDTH = 1200;
    public const HEIGHT = 630;

    private string $fontDir;
    private string $markPath;
    private string $uploadDir;

    /** Colour schemes: background, accent, second accent */
    private const PALETTES = [
        ['#0b1220', '#2563eb', '#38bdf8'],
        ['#120b1f', '#7c3aed', '#f472b6'],
        ['#07170f', '#059669', '#a3e635'],
        ['#1a0f08', '#ea580c', '#facc15'],
        ['#0a1418', '#0891b2', '#2dd4bf'],
        ['#1a0b10', '#e11d48', '#fb923c'],
        ['#0d0f1c', '#4f46e5', '#22d3ee'],
        ['#111111', '#64748b', '#f59e0b'],
    ];

    private const PATTERNS = ['grid', 'orbits', 'dots', 'stripes', 'blocks', 'waves', 'constellation'];

    public function __construct(string $projectDir, private readonly ?AiBannerArt $art = null)
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

        $background = $this->art?->create($title, $kicker);
        imagepng($this->render($title, $kicker, $credit, $slug . '|' . $title, $background), $dir . '/' . $file, 6);

        return '/uploads/' . $subdir . '/' . $file;
    }

    /**
     * @param string|null    $seed       picks palette and pattern; defaults to the title
     * @param \GdImage|null $background AI illustration to place under the text instead of generative art
     */
    public function render(string $title, string $kicker, ?string $credit = null, ?string $seed = null, ?\GdImage $background = null): \GdImage
    {
        $img = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        imagealphablending($img, true);
        imagesavealpha($img, true);

        $hash = crc32($seed ?? $title);
        [$bgHex, $accentHex, $accent2Hex] = self::PALETTES[$hash % count(self::PALETTES)];
        $white = $this->hex($img, '#f8fafc');
        $muted = $this->hex($img, '#cbd5e1');
        $line = imagecolorallocatealpha($img, 248, 250, 252, 100);
        [$ar, $ag, $ab] = sscanf($accentHex, '#%02x%02x%02x');

        if ($background !== null) {
            $this->cover($img, $background);
            $this->scrim($img);
        } else {
            imagefilledrectangle($img, 0, 0, self::WIDTH, self::HEIGHT, $this->hex($img, $bgHex));
            mt_srand($hash);
            $pattern = self::PATTERNS[intdiv($hash, count(self::PALETTES)) % count(self::PATTERNS)];
            $this->pattern($img, $pattern, $accentHex, $accent2Hex);
            mt_srand();
            $this->scrim($img, 70);
        }
        imagefilledrectangle($img, 0, self::HEIGHT - 8, self::WIDTH, self::HEIGHT, $this->hex($img, $accentHex));

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
        imagefilledrectangle($img, 72, 180, 72 + $chipW, 220, imagecolorallocatealpha($img, $ar, $ag, $ab, 40));
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

    /** Scale and centre-crop $source to fill the banner */
    private function cover(\GdImage $img, \GdImage $source): void
    {
        $sw = imagesx($source);
        $sh = imagesy($source);
        $scale = max(self::WIDTH / $sw, self::HEIGHT / $sh);
        $cw = (int) round(self::WIDTH / $scale);
        $ch = (int) round(self::HEIGHT / $scale);
        imagecopyresampled($img, $source, 0, 0, intdiv($sw - $cw, 2), intdiv($sh - $ch, 2), self::WIDTH, self::HEIGHT, $cw, $ch);
    }

    /** Darkens the left side (where the title sits) so white text stays legible on any background */
    private function scrim(\GdImage $img, int $strongest = 30): void
    {
        for ($x = 0; $x < self::WIDTH; $x += 8) {
            $alpha = (int) min(127, $strongest + ($x / self::WIDTH) ** 1.6 * (127 - $strongest));
            imagefilledrectangle($img, $x, 0, $x + 7, self::HEIGHT, imagecolorallocatealpha($img, 5, 8, 15, $alpha));
        }
    }

    private function pattern(\GdImage $img, string $pattern, string $accentHex, string $accent2Hex): void
    {
        [$r, $g, $b] = sscanf($accentHex, '#%02x%02x%02x');
        [$r2, $g2, $b2] = sscanf($accent2Hex, '#%02x%02x%02x');
        $a = static fn (int $alpha): int => imagecolorallocatealpha($img, $r, $g, $b, $alpha);
        $a2 = static fn (int $alpha): int => imagecolorallocatealpha($img, $r2, $g2, $b2, $alpha);
        $w = self::WIDTH;
        $h = self::HEIGHT;

        // A glow somewhere on the right gives every pattern depth
        $gx = mt_rand(700, 1150);
        $gy = mt_rand(0, $h);
        for ($rad = 640; $rad > 0; $rad -= 20) {
            imagefilledellipse($img, $gx, $gy, $rad * 2, $rad * 2, $a((int) max(0, 127 - (640 - $rad) / 640 * 24)));
        }

        switch ($pattern) {
            case 'grid':
                $step = mt_rand(5, 10) * 10;
                for ($x = $step; $x < $w; $x += $step) {
                    imageline($img, $x, 0, $x, $h, $a2(112));
                }
                for ($y = $step; $y < $h; $y += $step) {
                    imageline($img, 0, $y, $w, $y, $a2(118));
                }
                break;
            case 'orbits':
                $cx = mt_rand(800, 1100);
                $cy = mt_rand(150, 480);
                imagesetthickness($img, 2);
                for ($rad = 60; $rad < 900; $rad += mt_rand(40, 80)) {
                    imageellipse($img, $cx, $cy, $rad * 2, $rad * 2, $rad % 3 ? $a2(90) : $a(70));
                }
                for ($i = 0; $i < 6; ++$i) {
                    $t = mt_rand(0, 628) / 100;
                    $rad = mt_rand(100, 500);
                    imagefilledellipse($img, (int) ($cx + cos($t) * $rad), (int) ($cy + sin($t) * $rad), 14, 14, $a2(20));
                }
                imagesetthickness($img, 1);
                break;
            case 'dots':
                $step = mt_rand(22, 34);
                for ($y = $step; $y < $h; $y += $step) {
                    for ($x = $step; $x < $w; $x += $step) {
                        $size = max(2, (int) (($x / $w) * 14 * (0.6 + 0.4 * sin($y / 60 + $x / 200))));
                        imagefilledellipse($img, $x, $y, $size, $size, $a2(70));
                    }
                }
                break;
            case 'stripes':
                $band = mt_rand(30, 70);
                imagesetthickness($img, (int) ($band / 2));
                for ($x = -$h; $x < $w + $h; $x += $band * 2) {
                    imageline($img, $x, $h, $x + $h, 0, intdiv($x + $h, $band * 2) % 3 ? $a(105) : $a2(95));
                }
                imagesetthickness($img, 1);
                break;
            case 'blocks':
                for ($i = 0; $i < 14; ++$i) {
                    $bw = mt_rand(2, 6) * 40;
                    $bh = mt_rand(2, 5) * 40;
                    $x = mt_rand(10, 28) * 40;
                    $y = mt_rand(-2, 14) * 40;
                    imagefilledrectangle($img, $x, $y, $x + $bw, $y + $bh, $i % 2 ? $a(100) : $a2(108));
                }
                break;
            case 'waves':
                imagesetthickness($img, 2);
                $freq = mt_rand(3, 7) / 1000;
                for ($k = 0; $k < 14; ++$k) {
                    $base = 40 + $k * 44;
                    $amp = 20 + $k * 3;
                    $prev = null;
                    for ($x = 0; $x <= $w; $x += 10) {
                        $y = (int) ($base + sin($x * $freq + $k * 0.5) * $amp);
                        if ($prev !== null) {
                            imageline($img, $x - 10, $prev, $x, $y, $k % 2 ? $a(80) : $a2(85));
                        }
                        $prev = $y;
                    }
                }
                imagesetthickness($img, 1);
                break;
            default: // constellation
                $points = [];
                for ($i = 0; $i < 40; ++$i) {
                    $points[] = [mt_rand(450, $w), mt_rand(0, $h)];
                }
                foreach ($points as $i => [$x, $y]) {
                    foreach (array_slice($points, $i + 1) as [$x2, $y2]) {
                        if (hypot($x - $x2, $y - $y2) < 170) {
                            imageline($img, $x, $y, $x2, $y2, $a2(95));
                        }
                    }
                    imagefilledellipse($img, $x, $y, 8, 8, $a2(30));
                }
        }
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
