<?php

declare(strict_types=1);

namespace App\Service\Theme;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Public themes installed from the ES Marketplace store.
 *
 * Atlas is built in (templates/blog). A downloaded theme unzips into
 * templates/themes/<id>/blog/ (Twig overrides) and public/assets/themes/<id>/
 * (CSS, LICENSE, theme.json). Missing override files fall back to Atlas.
 */
final class InstalledThemes
{
    public const DEFAULT = 'atlas';

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {}

    /**
     * @return list<array{id: string, name: string}>
     */
    public function all(): array
    {
        $themes = [['id' => self::DEFAULT, 'name' => 'Atlas']];

        foreach (glob($this->projectDir . '/templates/themes/*/blog/layout.html.twig') ?: [] as $layout) {
            $id = basename(dirname($layout, 2));
            if ($id === self::DEFAULT || !preg_match('/^[a-z0-9][a-z0-9-]*$/', $id)) {
                continue;
            }

            $meta = json_decode((string) @file_get_contents($this->projectDir . '/public/assets/themes/' . $id . '/theme.json'), true);
            $themes[] = ['id' => $id, 'name' => is_array($meta) && is_string($meta['name'] ?? null) ? $meta['name'] : ucfirst($id)];
        }

        return $themes;
    }

    public function has(?string $id): bool
    {
        return $id !== null && in_array($id, array_column($this->all(), 'id'), true);
    }

    /**
     * Template directory to prepend for a theme, or null for Atlas / unknown ids.
     */
    public function templateDir(?string $id): ?string
    {
        if ($id === self::DEFAULT || !$this->has($id)) {
            return null;
        }

        return $this->projectDir . '/templates/themes/' . $id;
    }
}
