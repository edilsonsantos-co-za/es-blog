<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\SiteConfigService;
use App\Service\ThemeCatalog;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Loader\FilesystemLoader;

/**
 * Lets a public theme ship its own Twig templates.
 *
 * A theme whose template_path points inside templates/themes/ may override any
 * blog/* template by placing it at <template_path>/blog/<name>. Missing files
 * fall back to the shared templates/blog/ versions.
 */
final class ThemeTemplateSubscriber implements EventSubscriberInterface
{
    private const THEMES_DIR = '/templates/themes/';

    public function __construct(
        private readonly SiteConfigService $config,
        private readonly ThemeCatalog $catalog,
        #[Autowire(service: 'twig.loader.native_filesystem')]
        private readonly FilesystemLoader $loader,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 0]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || str_starts_with($event->getRequest()->getPathInfo(), '/admin')) {
            return;
        }

        $dir = $this->themeTemplateDir((string) $this->config->get('theme', 'default'));
        if ($dir !== null) {
            $this->loader->prependPath($dir);
        }
    }

    private function themeTemplateDir(string $slug): ?string
    {
        foreach ($this->catalog->getPublicThemes() as $theme) {
            if ($theme['slug'] !== $slug || $theme['template_path'] === null) {
                continue;
            }

            $root = realpath($this->projectDir . self::THEMES_DIR);
            $dir = realpath($this->projectDir . '/' . ltrim($theme['template_path'], '/'));

            // Only directories inside templates/themes/ are allowed.
            if ($root === false || $dir === false || !str_starts_with($dir, $root . DIRECTORY_SEPARATOR) || !is_dir($dir . '/blog')) {
                return null;
            }

            return $dir;
        }

        return null;
    }
}
