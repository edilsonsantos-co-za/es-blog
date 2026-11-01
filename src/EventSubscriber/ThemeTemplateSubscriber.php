<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\SiteConfigService;
use App\Service\Theme\InstalledThemes;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Loader\FilesystemLoader;

/**
 * Puts the active theme's Twig overrides in front of the built-in templates
 * for public pages. Admin pages always use the core templates.
 */
final class ThemeTemplateSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly SiteConfigService $config,
        private readonly InstalledThemes $themes,
        #[Autowire(service: 'twig.loader.native_filesystem')]
        private readonly FilesystemLoader $loader,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 0]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $path = $event->getRequest()->getPathInfo();
        $preview = $event->getRequest()->attributes->get('_route') === 'app_admin_post_preview';
        if (!$event->isMainRequest() || (str_starts_with($path, '/admin') && !$preview) || str_starts_with($path, '/login')) {
            return;
        }

        $dir = $this->themes->templateDir((string) $this->config->get('site.theme', InstalledThemes::DEFAULT));
        if ($dir !== null) {
            $this->loader->prependPath($dir);
        }
    }
}
