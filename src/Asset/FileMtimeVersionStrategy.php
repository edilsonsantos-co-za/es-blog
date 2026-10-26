<?php

namespace App\Asset;

use Symfony\Component\Asset\VersionStrategy\VersionStrategyInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Appends ?v=<file mtime> to public assets, so browsers fetch a stylesheet or script
 * again as soon as it changes instead of reusing a heuristically cached copy.
 */
final class FileMtimeVersionStrategy implements VersionStrategyInterface
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/public')]
        private readonly string $publicDir,
    ) {
    }

    public function getVersion(string $path): string
    {
        $file = $this->publicDir . '/' . ltrim(parse_url($path, PHP_URL_PATH) ?? '', '/');

        return is_file($file) ? (string) filemtime($file) : '';
    }

    public function applyVersion(string $path): string
    {
        $version = $this->getVersion($path);

        if ($version === '') {
            return $path;
        }

        return $path . (str_contains($path, '?') ? '&' : '?') . 'v=' . $version;
    }
}
