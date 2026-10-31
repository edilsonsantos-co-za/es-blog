<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Security\LogtoClient;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * After a local logout, also end the SSO session and come back to the blog home page.
 */
final class SsoLogoutSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly LogtoClient $logto,
        private readonly UrlGeneratorInterface $urls,
        #[Autowire('%env(default::DEFAULT_URI)%')]
        private readonly ?string $baseUri = null,
    ) {}

    public static function getSubscribedEvents(): array
    {
        // Run after the default logout listener, which sets the redirect to the logout target.
        return [LogoutEvent::class => ['onLogout', -100]];
    }

    public function onLogout(LogoutEvent $event): void
    {
        if (!$this->logto->isEnabled()) {
            return;
        }

        // Same base as the sign-in callback, so the post-logout redirect matches what Logto registered
        $home = $this->baseUri
            ? rtrim($this->baseUri, '/') . $this->urls->generate('app_blog_index')
            : $this->urls->generate('app_blog_index', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $event->setResponse(new RedirectResponse($this->logto->endSessionUrl($home)));
    }
}
