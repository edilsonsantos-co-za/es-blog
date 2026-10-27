<?php

declare(strict_types=1);

namespace App\Service\Theme;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Installs and removes verified theme packages under var/themes/<id>/.
 *
 * Files are written by the installer (never extracted by zip), staged in a
 * temporary folder and swapped in with a rename, so a failed install leaves
 * the previous version untouched.
 */
final class ThemeInstaller
{
    private readonly Filesystem $fs;

    public function __construct(
        private readonly ThemePackageVerifier $verifier,
        private readonly InstalledThemes $themes,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.cache_dir%/twig')]
        private readonly string $twigCacheDir,
    ) {
        $this->fs = new Filesystem();
    }

    /**
     * @return array<string, mixed> the installed theme's manifest
     */
    public function install(string $zipPath, string $installedBy): array
    {
        ['manifest' => $manifest, 'files' => $files, 'signed' => $signed] = $this->verifier->verify($zipPath);

        $root = $this->themes->dir();
        $this->fs->mkdir($root, 0775);
        $stage = $root . '/.stage-' . bin2hex(random_bytes(6));

        try {
            foreach ($files as $path => $data) {
                $this->fs->dumpFile($stage . '/' . $path, $data);
            }
            $this->fs->dumpFile($stage . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
            $this->fs->dumpFile($stage . '/.installed.json', json_encode([
                'signed' => $signed,
                'installedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
                'installedBy' => $installedBy,
            ], JSON_PRETTY_PRINT) . "\n");

            $target = $root . '/' . $manifest['id'];
            $old = null;
            if (is_dir($target)) {
                $old = $root . '/.old-' . bin2hex(random_bytes(6));
                $this->fs->rename($target, $old);
            }
            $this->fs->rename($stage, $target);
            if ($old !== null) {
                $this->fs->remove($old);
            }
        } finally {
            if (is_dir($stage)) {
                $this->fs->remove($stage);
            }
        }

        $this->clearTemplateCache();
        $this->logger->notice('Theme {id} {version} installed by {user}', [
            'id' => $manifest['id'], 'version' => $manifest['version'], 'user' => $installedBy, 'signed' => $signed,
        ]);

        return $manifest;
    }

    public function remove(string $id, string $removedBy): void
    {
        if ($id === InstalledThemes::DEFAULT || !$this->themes->has($id)) {
            throw new ThemePackageException('That theme is not installed.');
        }

        $this->fs->remove($this->themes->dir() . '/' . $id);
        $this->clearTemplateCache();
        $this->logger->notice('Theme {id} removed by {user}', ['id' => $id, 'user' => $removedBy]);
    }

    /**
     * Compiled Twig templates are keyed by file path, so an updated theme
     * would keep rendering the old version until the Twig cache is cleared.
     */
    private function clearTemplateCache(): void
    {
        if (is_dir($this->twigCacheDir)) {
            $this->fs->remove($this->twigCacheDir);
        }
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
    }
}
