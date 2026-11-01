<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Post;
use App\Entity\User;
use App\Message\PublishXPostMessage;
use App\Repository\XIntegrationRepository;
use App\Repository\XPostShareRepository;
use App\Security\Voter\PostVoter;
use App\Service\LinkedIn\TokenCipher;
use App\Service\X\XClient;
use App\Service\X\XPublisher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_AUTHOR')]
final class XController extends AbstractController
{
    private const STATE_KEY = 'x_oauth_state';
    private const VERIFIER_KEY = 'x_oauth_verifier';

    public function __construct(
        private readonly XClient $client,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('/admin/x', name: 'app_admin_x', methods: ['GET'])]
    public function index(XIntegrationRepository $integrations, XPostShareRepository $shares): Response
    {
        $user = $this->currentUser();
        $integration = $integrations->findForUser($user);

        return $this->render('admin/x/index.html.twig', [
            'configured' => $this->client->isConfigured(),
            'redirect_uri' => $this->client->redirectUri(),
            'scopes' => XClient::SCOPES,
            'integration' => $integration,
            'shares' => $shares->createQueryBuilder('s')->join('s.post', 'p')
                ->where('p.author = :author')->setParameter('author', $user)
                ->orderBy('s.sharedAt', 'DESC')->setMaxResults(20)->getQuery()->getResult(),
            'token_valid' => $integration !== null
                && $integration->getAccessToken() !== ''
                && ($integration->getTokenExpiresAt() === null || $integration->getTokenExpiresAt() > new \DateTimeImmutable()),
        ]);
    }

    #[Route('/admin/x/connect', name: 'app_admin_x_connect', methods: ['POST'])]
    public function connect(Request $request): Response
    {
        $this->guard($request, 'x_connect');
        if (!$this->client->isConfigured()) {
            $this->addFlash('error', 'Set X_CLIENT_ID, X_CLIENT_SECRET and X_REDIRECT_URI before connecting.');

            return $this->redirectToRoute('app_admin_x');
        }

        $state = bin2hex(random_bytes(32));
        $verifier = self::base64UrlEncode(random_bytes(32));
        $request->getSession()->set(self::STATE_KEY, $state);
        $request->getSession()->set(self::VERIFIER_KEY, $verifier);
        $challenge = self::base64UrlEncode(hash('sha256', $verifier, true));

        return $this->redirect($this->client->authorizationUrl($state, $challenge));
    }

    #[Route('/admin/x/callback', name: 'app_admin_x_callback', methods: ['GET'])]
    public function callback(Request $request, XIntegrationRepository $integrations, TokenCipher $cipher): Response
    {
        $expectedState = $request->getSession()->remove(self::STATE_KEY);
        $verifier = $request->getSession()->remove(self::VERIFIER_KEY);
        if (!is_string($expectedState) || !is_string($verifier)
            || !hash_equals($expectedState, (string) $request->query->get('state'))) {
            $this->addFlash('error', 'X sign-in expired or was tampered with. Try again.');

            return $this->redirectToRoute('app_admin_x');
        }
        if ($request->query->has('error')) {
            $this->addFlash('error', 'X authorization was not completed.');

            return $this->redirectToRoute('app_admin_x');
        }
        $code = (string) $request->query->get('code');
        if ($code === '') {
            $this->addFlash('error', 'X did not return an authorization code.');

            return $this->redirectToRoute('app_admin_x');
        }

        try {
            $tokens = $this->client->exchangeCode($code, $verifier);
            $account = $this->client->userInfo($tokens['access_token']);
        } catch (\Throwable) {
            $this->addFlash('error', 'Could not finish connecting X. Check app permissions and try again.');

            return $this->redirectToRoute('app_admin_x');
        }

        $user = $this->currentUser();
        $existing = $integrations->findByXUserId($account['id']);
        if ($existing !== null && $existing->getUser()->getId() !== $user->getId()) {
            $this->addFlash('error', 'That X account is already connected to another blog author.');

            return $this->redirectToRoute('app_admin_x');
        }
        $integration = $integrations->findForUser($user) ?? new XIntegration();
        $integration
            ->setUser($user)
            ->setXUserId($account['id'])
            ->setUsername($account['username'])
            ->setAccessToken($cipher->encrypt($tokens['access_token']))
            ->setRefreshToken($tokens['refresh_token'] !== null ? $cipher->encrypt($tokens['refresh_token']) : null)
            ->setTokenExpiresAt($tokens['expires_in'] > 0
                ? new \DateTimeImmutable('+' . $tokens['expires_in'] . ' seconds')
                : null);
        $this->em->persist($integration);
        $this->em->flush();
        $this->addFlash('success', sprintf('X connected%s.', $account['username'] ? ' as @' . $account['username'] : ''));

        return $this->redirectToRoute('app_admin_x');
    }

    #[Route('/admin/x/disconnect', name: 'app_admin_x_disconnect', methods: ['POST'])]
    public function disconnect(Request $request, XIntegrationRepository $integrations): Response
    {
        $this->guard($request, 'x_disconnect');
        $integration = $integrations->findForUser($this->currentUser());
        if ($integration !== null) {
            $this->em->remove($integration);
            $this->em->flush();
        }
        $this->addFlash('success', 'X disconnected. Existing share history is retained.');

        return $this->redirectToRoute('app_admin_x');
    }

    #[Route('/admin/x/share/{id}', name: 'app_admin_x_share', methods: ['POST'])]
    public function share(
        Post $post,
        Request $request,
        XIntegrationRepository $integrations,
        XPublisher $publisher,
        MessageBusInterface $bus,
    ): Response {
        $this->denyAccessUnlessGranted(PostVoter::PUBLISH, $post);
        $this->guard($request, 'x_share_' . $post->getId());
        if (!$post->isPublished()) {
            $this->addFlash('error', 'Only currently published posts can be shared on X.');
        } elseif ($publisher->hasShared($post)) {
            $this->addFlash('info', 'This post has already been shared (or a share is in progress) on X.');
        } elseif ($integrations->findForUser($post->getAuthor()) === null) {
            $this->addFlash('error', 'Connect X for this post author before sharing.');
        } elseif (XPublisher::sanitizeText((string) $post->getCustomFieldValue(XPublisher::TEXT_FIELD)) === '') {
            $this->addFlash('error', 'Write and save an X post draft before sharing.');
        } else {
            $bus->dispatch(
                new PublishXPostMessage((int) $post->getId(), $publisher->reviewHash($post)),
                [new TransportNamesStamp(['async'])],
            );
            $this->addFlash('success', 'Reviewed post queued for X.');
        }

        return $this->redirectToRoute('app_admin_post_edit', ['id' => $post->getId()]);
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function guard(Request $request, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
