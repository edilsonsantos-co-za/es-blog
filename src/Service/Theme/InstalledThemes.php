<?php

declare(strict_types=1);

namespace App\Service\Theme;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Public themes installed from the ES Marketplace store.
 *
 * Atlas is built in (templates/blog). Installed themes live in var/themes/<id>/
 * (a persistent volume in production): manifest.json, templates/blog/ (Twig
 * overrides) and assets/ (served by ThemeAssetController). Missing override
 * files fall back to Atlas.
 */
final class InstalledThemes
{
    public const DEFAULT = 'atlas';

    public function __construct(
        #[Autowire('%kernel.project_dir%/var/themes')]
        private readonly string $themesDir,
    ) {}

    public function dir(): string
    {
        return $this->themesDir;
    }

    /**
     * @return list<array{id: string, name: string, version: ?string, author: ?string, license: ?string, summary: ?string, repository: ?string, signed: bool, preview: ?string}>
     */
    public function all(): array
    {
        $themes = [[
            'id' => self::DEFAULT, 'name' => 'Atlas', 'version' => null, 'author' => 'Edilson Santos', 'license' => 'MIT',
            'summary' => 'The built-in design.', 'repository' => null, 'signed' => true, 'preview' => null,
        ]];

        foreach (glob($this->themesDir . '/*/manifest.json') ?: [] as $file) {
            $id = basename(dirname($file));
            if ($id === self::DEFAULT || !preg_match('/^[a-z0-9][a-z0-9-]*$/', $id) || !is_file(dirname($file) . '/templates/blog/layout.html.twig')) {
                continue;
            }

            $m = json_decode((string) file_get_contents($file), true);
            $state = json_decode((string) @file_get_contents(dirname($file) . '/.installed.json'), true);
            $preview = null;
            foreach (['preview.jpg', 'preview.png'] as $name) {
                if (is_file(dirname($file) . '/' . $name)) {
                    $preview = $name;
                    break;
                }
            }

            $themes[] = [
                'id' => $id,
                'name' => is_string($m['name'] ?? null) ? $m['name'] : ucfirst($id),
                'version' => is_string($m['version'] ?? null) ? $m['version'] : null,
                'author' => is_string($m['author'] ?? null) ? $m['author'] : null,
                'license' => is_string($m['license'] ?? null) ? $m['license'] : null,
                'summary' => is_string($m['summary'] ?? null) ? $m['summary'] : null,
                'repository' => is_string($m['repository'] ?? null) ? $m['repository'] : null,
                'signed' => (bool) ($state['signed'] ?? false),
                'preview' => $preview,
            ];
        }

        return $themes;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(?string $id): ?array
    {
        foreach ($this->all() as $theme) {
            if ($theme['id'] === $id) {
                return $theme;
            }
        }

        return null;
    }

    public function has(?string $id): bool
    {
        return $this->get($id) !== null;
    }

    /**
     * Template directory to prepend for a theme, or null for Atlas / unknown ids.
     */
    public function templateDir(?string $id): ?string
    {
        if ($id === self::DEFAULT || !$this->has($id)) {
            return null;
        }

        return $this->themesDir . '/' . $id . '/templates';
    }
}
