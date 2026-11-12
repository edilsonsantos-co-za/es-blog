<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\SiteConfig;
use App\Repository\SiteConfigRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Reads and writes site-wide configuration stored in the `config` DB table.
 *
 * All reads are cached in the Symfony cache pool so that high-traffic pages
 * don't query the DB for every request.  Writes invalidate the cache.
 *
 * Keys mirror the v1 config.ini conventions (e.g. "site.title", "blog.description").
 */
final class SiteConfigService
{
    private const CACHE_TTL = 600; // 10 minutes

    public function __construct(
        private readonly SiteConfigRepository $repo,
        private readonly CacheInterface $cache,
        #[Autowire('%env(default::SITE_URL)%')] private readonly ?string $siteUrlEnv = null,
    ) {}

    public function get(string $key, ?string $default = null): ?string
    {
        // site.url left empty in Admin → Settings falls back to SITE_URL from the server env, so the
        // newsletter, share links and absolute URLs work on a fresh install
        if ($key === 'site.url') {
            $value = $this->read($key, null);

            return ($value ?? '') !== '' ? $value : (rtrim((string) $this->siteUrlEnv, '/') ?: $default);
        }

        return $this->read($key, $default);
    }

    private function read(string $key, ?string $default): ?string
    {
        return $this->cache->get(
            $this->cacheKey($key),
            function (ItemInterface $item) use ($key, $default): ?string {
                $item->expiresAfter(self::CACHE_TTL);

                return $this->repo->get($key, $default);
            }
        );
    }

    public function set(string $key, ?string $value): void
    {
        $this->repo->set($key, $value);
        $this->cache->delete($this->cacheKey($key));
    }

    /**
     * Set multiple keys at once inside a single flush.
     *
     * @param array<string, string|null> $values
     */
    public function setBulk(array $values): void
    {
        foreach ($values as $key => $value) {
            $this->repo->set($key, $value);
            $this->cache->delete($this->cacheKey($key));
        }
    }

    /** @return array<string, string|null> */
    public function getAll(): array
    {
        return $this->repo->getAll();
    }

    /**
     * Return config as a typed boolean.
     * Truthy values: "true", "1", "yes", "on".
     */
    public function getBool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);
        if ($value === null) {
            return $default;
        }

        return in_array(strtolower($value), ['true', '1', 'yes', 'on'], true);
    }

    public function getInt(string $key, int $default = 0): int
    {
        $value = $this->get($key);

        return $value !== null ? (int) $value : $default;
    }

    private function cacheKey(string $key): string
    {
        return 'site_config_' . str_replace(['.', ' '], '_', $key);
    }
}
