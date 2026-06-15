<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Yaml\Yaml;

final class ThemeCatalog
{
    private const CONFIG_PATH = '/config/themes.yaml';

    public function __construct(
        private readonly string $projectDir,
    ) {}

    /** @return list<array{slug: string, name: string, status: string, description: string, template_path?: string|null, asset_path?: string|null}> */
    public function getPublicThemes(?string $currentSlug = null): array
    {
        return $this->withCurrentTheme($this->readSection('public'), $currentSlug, 'public');
    }

    /** @return list<array{slug: string, name: string, status: string, description: string, template_path?: string|null, asset_path?: string|null}> */
    public function getAdminThemes(?string $currentSlug = null): array
    {
        return $this->withCurrentTheme($this->readSection('admin'), $currentSlug, 'admin');
    }

    /** @return list<array{slug: string, name: string, status: string, description: string, template_path?: string|null, asset_path?: string|null}> */
    private function readSection(string $section): array
    {
        $path = $this->projectDir . self::CONFIG_PATH;
        if (!is_file($path)) {
            return [];
        }

        $catalog = Yaml::parseFile($path);
        $themes = is_array($catalog[$section] ?? null) ? $catalog[$section] : [];

        return array_values(array_filter(array_map(
            fn (mixed $theme): ?array => $this->normalizeTheme($theme),
            $themes,
        )));
    }

    /** @return array{slug: string, name: string, status: string, description: string, template_path?: string|null, asset_path?: string|null}|null */
    private function normalizeTheme(mixed $theme): ?array
    {
        if (!is_array($theme) || !is_string($theme['slug'] ?? null) || trim($theme['slug']) === '') {
            return null;
        }

        $slug = trim($theme['slug']);

        return [
            'slug' => $slug,
            'name' => is_string($theme['name'] ?? null) && trim($theme['name']) !== '' ? trim($theme['name']) : $slug,
            'status' => is_string($theme['status'] ?? null) && trim($theme['status']) !== '' ? trim($theme['status']) : 'manual',
            'description' => is_string($theme['description'] ?? null) ? trim($theme['description']) : '',
            'template_path' => is_string($theme['template_path'] ?? null) ? trim($theme['template_path']) : null,
            'asset_path' => is_string($theme['asset_path'] ?? null) ? trim($theme['asset_path']) : null,
        ];
    }

    /** @param list<array{slug: string, name: string, status: string, description: string, template_path?: string|null, asset_path?: string|null}> $themes */
    private function withCurrentTheme(array $themes, ?string $currentSlug, string $section): array
    {
        $currentSlug = trim((string) $currentSlug);
        if ($currentSlug === '') {
            return $themes;
        }

        foreach ($themes as $theme) {
            if ($theme['slug'] === $currentSlug) {
                return $themes;
            }
        }

        array_unshift($themes, [
            'slug' => $currentSlug,
            'name' => sprintf('Current manual %s theme', $section),
            'status' => 'manual',
            'description' => 'Saved in site configuration but not yet listed in config/themes.yaml.',
            'template_path' => null,
            'asset_path' => null,
        ]);

        return $themes;
    }
}
