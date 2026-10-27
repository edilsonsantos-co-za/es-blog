<?php

declare(strict_types=1);

namespace App\Service\Theme;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reads the ES Marketplace catalogue and downloads blog theme packages.
 *
 * Requests only ever go to THEME_STORE_URL; catalogue download paths must be
 * relative to it, and each download is checked against the catalogue's SHA-256
 * before the installer verifies its signature.
 */
final class ThemeStore
{
    public function __construct(
        private readonly HttpClientInterface $http,
        #[Autowire('%env(THEME_STORE_URL)%')]
        private readonly string $storeUrl,
    ) {}

    /**
     * Blog themes that install from the admin.
     *
     * @return list<array{id: string, name: string, version: string, summary: string, author: string, license: string, preview: ?string, repository: ?string, size: int}>
     */
    public function themes(): array
    {
        $response = $this->http->request('GET', $this->url('catalog.json'), ['timeout' => 8, 'max_redirects' => 0]);
        $catalog = $response->toArray();

        $themes = [];
        foreach ($catalog['items'] ?? [] as $item) {
            if (($item['app'] ?? null) !== 'blog' || ($item['install'] ?? null) !== 'admin' || !is_string($item['download'] ?? null)) {
                continue;
            }
            $themes[] = [
                'id' => (string) $item['id'],
                'name' => (string) ($item['name'] ?? $item['id']),
                'version' => (string) ($item['version'] ?? ''),
                'summary' => (string) ($item['summary'] ?? ''),
                'author' => (string) ($item['author'] ?? ''),
                'license' => (string) ($item['license'] ?? ''),
                'preview' => is_string($item['preview'] ?? null) ? $item['preview'] : null,
                'repository' => is_string($item['repository'] ?? null) ? $item['repository'] : null,
                'size' => (int) ($item['size'] ?? 0),
            ];
        }

        return $themes;
    }

    /**
     * Downloads a theme package to a temporary file and returns its path.
     */
    public function download(string $id): string
    {
        $catalog = $this->http->request('GET', $this->url('catalog.json'), ['timeout' => 8, 'max_redirects' => 0])->toArray();
        $item = null;
        foreach ($catalog['items'] ?? [] as $candidate) {
            if (($candidate['id'] ?? null) === $id && ($candidate['app'] ?? null) === 'blog' && ($candidate['install'] ?? null) === 'admin') {
                $item = $candidate;
                break;
            }
        }
        if ($item === null || !is_string($item['download'] ?? null) || !is_string($item['sha256'] ?? null)) {
            throw new ThemePackageException('The store does not offer that theme for EdFolio Blog.');
        }

        $response = $this->http->request('GET', $this->url($item['download']), [
            'timeout' => 30,
            'max_redirects' => 0,
            'max_duration' => 60,
        ]);
        if ($response->getStatusCode() !== 200) {
            throw new ThemePackageException('The store could not send that package. Try again later.');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'theme-');
        $handle = fopen($tmp, 'wb');
        $size = 0;
        foreach ($this->http->stream($response) as $chunk) {
            $data = $chunk->getContent();
            $size += strlen($data);
            if ($size > ThemePackageVerifier::MAX_PACKAGE_BYTES) {
                fclose($handle);
                @unlink($tmp);
                throw new ThemePackageException('The package is larger than 20 MB.');
            }
            fwrite($handle, $data);
        }
        fclose($handle);

        if (!hash_equals($item['sha256'], (string) hash_file('sha256', $tmp))) {
            @unlink($tmp);
            throw new ThemePackageException('The download does not match the store catalogue. Try again later.');
        }

        return $tmp;
    }

    private function url(string $path): string
    {
        if (str_contains($path, '://') || str_starts_with($path, '/') || str_contains($path, '..') || str_contains($path, '\\')) {
            throw new ThemePackageException('The store catalogue has an invalid download path.');
        }

        return rtrim($this->storeUrl, '/') . '/' . $path;
    }
}
