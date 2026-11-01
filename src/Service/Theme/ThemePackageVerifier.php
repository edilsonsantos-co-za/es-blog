<?php

declare(strict_types=1);

namespace App\Service\Theme;

use App\Service\AppVersion;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Checks an ES Marketplace theme package before anything is written to disk.
 *
 * A package is a zip with manifest.json (id, version, app, requires and a
 * SHA-256 for every file), manifest.sig (Ed25519 signature over manifest.json
 * by a key in config/theme-keys/) and the theme files. Templates are code, so
 * only signed packages install unless THEMES_ALLOW_UNSIGNED is on.
 */
final class ThemePackageVerifier
{
    public const APP = 'edfolio-blog';
    public const MAX_PACKAGE_BYTES = 20 * 1024 * 1024;
    private const MAX_UNPACKED_BYTES = 40 * 1024 * 1024;
    private const MAX_FILES = 500;
    private const ASSET_EXTENSIONS = ['css', 'js', 'map', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'ico', 'woff', 'woff2', 'ttf', 'otf'];

    public function __construct(
        private readonly AppVersion $appVersion,
        #[Autowire('%kernel.project_dir%/config/theme-keys')]
        private readonly string $keysDir,
        #[Autowire('%env(bool:THEMES_ALLOW_UNSIGNED)%')]
        private readonly bool $allowUnsigned = false,
    ) {}

    /**
     * @return array{manifest: array<string, mixed>, files: array<string, string>, signed: bool}
     *         files maps each package path to its contents
     */
    public function verify(string $zipPath): array
    {
        if (!is_file($zipPath) || filesize($zipPath) > self::MAX_PACKAGE_BYTES) {
            throw new ThemePackageException('The package is missing or larger than 20 MB.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::RDONLY) !== true) {
            throw new ThemePackageException('The file is not a valid zip package.');
        }

        try {
            if ($zip->numFiles > self::MAX_FILES) {
                throw new ThemePackageException('The package has too many files.');
            }

            $manifestJson = $zip->getFromName('manifest.json');
            if ($manifestJson === false || strlen($manifestJson) > 256 * 1024) {
                throw new ThemePackageException('The package has no manifest.json. Download it again from the store.');
            }

            $signed = $this->checkSignature($manifestJson, $zip->getFromName('manifest.sig'));
            $manifest = $this->checkManifest($manifestJson);
            $files = $this->readFiles($zip, $manifest['files']);
        } finally {
            $zip->close();
        }

        return ['manifest' => $manifest, 'files' => $files, 'signed' => $signed];
    }

    private function checkSignature(string $manifestJson, string|false $sigJson): bool
    {
        $sig = $sigJson === false ? null : json_decode($sigJson, true);
        $key = is_array($sig) && is_string($sig['key'] ?? null) && preg_match('/^[a-z0-9-]+$/', $sig['key'])
            ? @file_get_contents($this->keysDir . '/' . $sig['key'] . '.pub')
            : false;
        $signature = is_array($sig) && is_string($sig['sig'] ?? null) ? base64_decode($sig['sig'], true) : false;
        $public = $key === false ? false : base64_decode(trim($key), true);

        $valid = $signature !== false && $public !== false
            && strlen($signature) === SODIUM_CRYPTO_SIGN_BYTES
            && strlen($public) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            && sodium_crypto_sign_verify_detached($signature, $manifestJson, $public);

        if (!$valid && !$this->allowUnsigned) {
            throw new ThemePackageException('The package is not signed by the ES Marketplace store, or it was changed after signing.');
        }

        return $valid;
    }

    /**
     * @return array<string, mixed>
     */
    private function checkManifest(string $json): array
    {
        $m = json_decode($json, true);
        if (!is_array($m) || ($m['schema'] ?? null) !== 1) {
            throw new ThemePackageException('The manifest is not a supported format.');
        }
        if (!is_string($m['id'] ?? null) || !preg_match('/^[a-z0-9][a-z0-9-]{0,40}$/', $m['id']) || $m['id'] === InstalledThemes::DEFAULT) {
            throw new ThemePackageException('The manifest has an invalid theme id.');
        }
        if (($m['app'] ?? null) !== self::APP) {
            throw new ThemePackageException(sprintf('This theme is for "%s", not ES Blog.', is_string($m['app'] ?? null) ? $m['app'] : 'another app'));
        }
        if (!is_string($m['name'] ?? null) || $m['name'] === '' || mb_strlen($m['name']) > 80) {
            throw new ThemePackageException('The manifest has no theme name.');
        }
        if (!is_string($m['version'] ?? null) || !preg_match('/^\d+\.\d+\.\d+$/', $m['version'])) {
            throw new ThemePackageException('The manifest has an invalid version.');
        }
        if (!self::satisfies($this->appVersion->getVersion(), (string) ($m['requires'] ?? ''))) {
            throw new ThemePackageException(sprintf('This theme needs ES Blog %s; this site runs %s.', $m['requires'], $this->appVersion->getVersion()));
        }
        if (!is_array($m['files'] ?? null) || !isset($m['files']['templates/blog/layout.html.twig'])) {
            throw new ThemePackageException('The package has no blog layout template.');
        }
        foreach ($m['files'] as $path => $hash) {
            if (!is_string($path) || !self::allowedPath($path) || !is_string($hash) || !preg_match('/^sha256:[0-9a-f]{64}$/', $hash)) {
                throw new ThemePackageException(sprintf('The package lists a file that is not allowed: %s', (string) $path));
            }
        }

        return $m;
    }

    /**
     * @param array<string, string> $expected
     * @return array<string, string>
     */
    private function readFiles(\ZipArchive $zip, array $expected): array
    {
        $files = [];
        $total = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (str_ends_with($name, '/') || $name === 'manifest.json' || $name === 'manifest.sig') {
                continue;
            }
            if (!isset($expected[$name])) {
                throw new ThemePackageException(sprintf('The package contains a file its manifest does not list: %s', $name));
            }

            $stat = $zip->statIndex($i);
            $total += (int) ($stat['size'] ?? 0);
            if ($total > self::MAX_UNPACKED_BYTES) {
                throw new ThemePackageException('The package unpacks to more than 40 MB.');
            }

            $data = $zip->getFromIndex($i);
            if ($data === false || 'sha256:' . hash('sha256', $data) !== $expected[$name]) {
                throw new ThemePackageException(sprintf('A file does not match its checksum: %s', $name));
            }
            $files[$name] = $data;
        }

        $missing = array_diff(array_keys($expected), array_keys($files));
        if ($missing !== []) {
            throw new ThemePackageException(sprintf('The package is missing a file: %s', reset($missing)));
        }

        return $files;
    }

    /**
     * Package paths are relative, without "..", and limited to Twig templates,
     * static assets, the licence and a preview image.
     */
    public static function allowedPath(string $path): bool
    {
        if (!preg_match('#^[A-Za-z0-9_-][A-Za-z0-9._-]*(/[A-Za-z0-9_-][A-Za-z0-9._-]*)*$#', $path)) {
            return false;
        }
        if (in_array($path, ['LICENSE', 'LICENSE.txt', 'README.md', 'preview.jpg', 'preview.png'], true)) {
            return true;
        }
        if (str_starts_with($path, 'templates/blog/')) {
            return str_ends_with($path, '.html.twig');
        }
        if (str_starts_with($path, 'assets/')) {
            return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::ASSET_EXTENSIONS, true);
        }

        return false;
    }

    /**
     * Space-separated constraints such as ">=0.1.0 <1.0.0". Empty means any version.
     */
    public static function satisfies(string $version, string $constraints): bool
    {
        foreach (preg_split('/\s+/', trim($constraints), -1, PREG_SPLIT_NO_EMPTY) as $constraint) {
            if (!preg_match('/^(>=|<=|>|<|=)?(\d+\.\d+\.\d+)$/', $constraint, $m)) {
                return false;
            }
            if (!version_compare($version, $m[2], $m[1] === '' ? '==' : ($m[1] === '=' ? '==' : $m[1]))) {
                return false;
            }
        }

        return true;
    }
}
