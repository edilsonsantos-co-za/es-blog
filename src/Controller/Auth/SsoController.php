<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\EventSubscriber\SingleSignOutSubscriber;
use App\Repository\UserRepository;
use App\Security\LogtoClient;
use App\Security\SsoAuthenticator;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Sign in through Logto. Only existing users can sign in: the verified email from Logto
 * must match a user record; accounts are never created here.
 */
class SsoController extends AbstractController
{
    public function __construct(
        private readonly LogtoClient $logto,
        private readonly UserRepository $users,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(default::DEFAULT_URI)%')]
        private readonly ?string $baseUri = null,
    ) {}

    #[Route('/login/sso', name: 'app_login_sso', methods: ['GET'])]
    public function start(Request $request): Response
    {
        if (!$this->logto->isEnabled()) {
            throw $this->createNotFoundException();
        }

        $auth = $this->logto->authorizationRequest($this->callbackUrl());
        $request->getSession()->set('sso', ['state' => $auth['state'], 'nonce' => $auth['nonce'], 'verifier' => $auth['verifier']]);

        return new RedirectResponse($auth['url']);
    }

    #[Route('/login/sso/callback', name: 'app_login_sso_callback', methods: ['GET'])]
    public function callback(Request $request, Security $security): Response
    {
        if (!$this->logto->isEnabled()) {
            throw $this->createNotFoundException();
        }

        $session = $request->getSession();
        $pending = $session->get('sso');
        $session->remove('sso');

        if (!is_array($pending) || !hash_equals($pending['state'], (string) $request->query->get('state', ''))) {
            return $this->fail('Sign-in session expired. Please try again.');
        }

        if ($request->query->has('error') || !$request->query->has('code')) {
            return $this->fail('Sign-in was cancelled.');
        }

        try {
            $claims = $this->logto->exchangeCode((string) $request->query->get('code'), $this->callbackUrl(), $pending['verifier'], $pending['nonce']);
        } catch (\Throwable $e) {
            $this->logger->warning('SSO sign-in failed: {message}', ['message' => $e->getMessage()]);

            return $this->fail('Single sign-on failed. Please try again.');
        }

        $email = mb_strtolower((string) ($claims['email'] ?? ''));
        $user = ($email !== '' && ($claims['email_verified'] ?? false) === true)
            ? $this->users->findOneBy(['email' => $email])
            : null;

        if ($user === null || !LogtoClient::isOwner($claims)) {
            return $this->fail('That account does not have access to this blog.');
        }

        $security->login($user, SsoAuthenticator::class, 'main');
        // Single sign-out across ES apps: remember the marker seen now (see SingleSignOutSubscriber)
        $request->getSession()->set(SingleSignOutSubscriber::SESSION_KEY, (string) $request->cookies->get(SingleSignOutSubscriber::COOKIE, ''));

        return $this->redirectToRoute('app_admin_dashboard');
    }

    /**
     * Built from DEFAULT_URI (the public https address) rather than the request, so the redirect_uri
     * matches what Logto has registered whether the page was opened over http or behind a proxy.
     */
    private function callbackUrl(): string
    {
        if ($this->baseUri) {
            return rtrim($this->baseUri, '/') . $this->generateUrl('app_login_sso_callback');
        }

        return $this->generateUrl('app_login_sso_callback', [], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /**
     * No error screen of our own: end the SSO session (so another account can be used) and come back
     * to /login, which goes straight to the SSO sign-in page. The reason is only logged.
     */
    private function fail(string $message): RedirectResponse
    {
        $this->logger->notice('SSO sign-in refused: ' . $message);
        $login = $this->baseUri
            ? rtrim($this->baseUri, '/') . $this->generateUrl('app_login')
            : $this->generateUrl('app_login', [], UrlGeneratorInterface::ABSOLUTE_URL);

        return $this->redirect($this->logto->endSessionUrl($login));
    }
}
