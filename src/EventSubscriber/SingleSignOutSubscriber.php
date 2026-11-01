<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Single sign-out across the ES apps. "Sign out" in any app's <es-apps> menu writes a fresh
 * `es-signout` cookie on the parent domain; the SSO sign-in stores the value it saw then. When the
 * cookie no longer matches, the person signed out somewhere else: end this session as well.
 */
final class SingleSignOutSubscriber implements EventSubscriberInterface
{
    public const COOKIE = 'es-signout';
    public const SESSION_KEY = 'es_signout_seen';

    public function __construct(private readonly Security $security) {}

    public static function getSubscribedEvents(): array
    {
        // After the firewall (priority 8) has loaded the signed-in user
        return [KernelEvents::REQUEST => ['onRequest', 0]];
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $marker = $request->cookies->get(self::COOKIE);
        if (!$event->isMainRequest() || $marker === null || $marker === '' || !$request->hasSession()) {
            return;
        }
        if (str_starts_with($request->getPathInfo(), '/login') || str_starts_with($request->getPathInfo(), '/2fa') || $request->getPathInfo() === '/logout') {
            return;
        }
        if ($this->security->getUser() === null) {
            return;
        }
        if ($request->getSession()->get(self::SESSION_KEY, '') === $marker) {
            return;
        }

        // Ends the session and the SSO session (SsoLogoutSubscriber), then lands back on the blog
        $response = $this->security->logout(false);
        $event->setResponse($response ?? new RedirectResponse($request->getUri()));
    }
}
