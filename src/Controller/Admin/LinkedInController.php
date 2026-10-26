<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\LinkedInIntegration;
use App\Entity\Post;
use App\Entity\User;
use App\Repository\LinkedInIntegrationRepository;
use App\Repository\PostRepository;
use App\Service\LinkedIn\LinkedInClient;
use App\Service\LinkedIn\LinkedInPublisher;
use App\Service\LinkedIn\TokenCipher;
use App\Service\SiteConfigService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Connects the owner's LinkedIn profile so published posts are shared automatically.
 */
#[IsGranted('ROLE_ADMIN')]
class LinkedInController extends AbstractController
{
    private const STATE_KEY = 'linkedin_oauth_state';

    public function __construct(
        private readonly LinkedInClient $client,
        private readonly LinkedInPublisher $publisher,
        private readonly SiteConfigService $config,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('/admin/linkedin', name: 'app_admin_linkedin', methods: ['GET'])]
    public function index(PostRepository $posts): Response
    {
        $integration = $this->publisher->connection();

        return $this->render('admin/linkedin/index.html.twig', [
            'configured' => $this->client->isConfigured(),
            'redirect_uri' => $this->client->redirectUri(),
            'scopes' => LinkedInClient::SCOPES,
            'integration' => $integration,
            'token_valid' => $integration !== null && $this->publisher->accessToken($integration) !== null,
            'auto_share' => $this->config->getBool('linkedin.auto_share', true),
            'shared' => $posts->createQueryBuilder('p')
                ->where('p.linkedInSharedAt IS NOT NULL')
                ->orderBy('p.linkedInSharedAt', 'DESC')
                ->setMaxResults(10)->getQuery()->getResult(),
            'unshared' => $posts->createQueryBuilder('p')
                ->where('p.status = :published AND p.linkedInPostUrn IS NULL')
                ->setParameter('published', 'published')
                ->orderBy('p.publishedAt', 'DESC')
                ->setMaxResults(10)->getQuery()->getResult(),
        ]);
    }

    #[Route('/admin/linkedin/connect', name: 'app_admin_linkedin_connect', methods: ['POST'])]
    public function connect(Request $request): Response
    {
        $this->guard($request, 'linkedin_connect');
        if (!$this->client->isConfigured()) {
            $this->addFlash('error', 'Set LINKEDIN_CLIENT_ID, LINKEDIN_CLIENT_SECRET and LINKEDIN_REDIRECT_URI first.');

            return $this->redirectToRoute('app_admin_linkedin');
        }
        $state = bin2hex(random_bytes(16));
        $request->getSession()->set(self::STATE_KEY, $state);

        return $this->redirect($this->client->authorizationUrl($state));
    }

    #[Route('/admin/linkedin/callback', name: 'app_admin_linkedin_callback', methods: ['GET'])]
    public function callback(Request $request, LinkedInIntegrationRepository $integrations, TokenCipher $cipher): Response
    {
        $expected = $request->getSession()->remove(self::STATE_KEY);
        if (!is_string($expected) || !hash_equals($expected, (string) $request->query->get('state'))) {
            $this->addFlash('error', 'LinkedIn sign-in expired or was tampered with. Try again.');

            return $this->redirectToRoute('app_admin_linkedin');
        }
        if ($request->query->has('error')) {
            $this->addFlash('error', 'LinkedIn: ' . $request->query->get('error_description', $request->query->get('error')));

            return $this->redirectToRoute('app_admin_linkedin');
        }

        try {
            $token = $this->client->exchangeCode((string) $request->query->get('code'));
            $member = $this->client->userInfo($token['access_token']);
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Could not finish connecting LinkedIn: ' . $e->getMessage());

            return $this->redirectToRoute('app_admin_linkedin');
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        // One connected profile: replace whatever was there before.
        foreach ($integrations->findAll() as $old) {
            $this->em->remove($old);
        }
        $this->em->flush();

        $integration = (new LinkedInIntegration())
            ->setUser($user)
            ->setLinkedInUserId($member['sub'])
            ->setUserUrn('urn:li:person:' . $member['sub'])
            ->setAccessToken($cipher->encrypt($token['access_token']))
            ->setTokenExpiresAt($token['expires_in'] > 0 ? new \DateTime('+' . $token['expires_in'] . ' seconds') : null);
        $this->em->persist($integration);
        $this->em->flush();

        $this->addFlash('success', sprintf('LinkedIn connected%s.', $member['name'] ? ' as ' . $member['name'] : ''));

        return $this->redirectToRoute('app_admin_linkedin');
    }

    #[Route('/admin/linkedin/disconnect', name: 'app_admin_linkedin_disconnect', methods: ['POST'])]
    public function disconnect(Request $request, LinkedInIntegrationRepository $integrations): Response
    {
        $this->guard($request, 'linkedin_disconnect');
        foreach ($integrations->findAll() as $integration) {
            $this->em->remove($integration);
        }
        $this->em->flush();
        $this->addFlash('success', 'LinkedIn disconnected. Posts will no longer be shared.');

        return $this->redirectToRoute('app_admin_linkedin');
    }

    #[Route('/admin/linkedin/settings', name: 'app_admin_linkedin_settings', methods: ['POST'])]
    public function settings(Request $request): Response
    {
        $this->guard($request, 'linkedin_settings');
        $this->config->set('linkedin.auto_share', $request->request->getBoolean('auto_share') ? '1' : '0');
        $this->addFlash('success', 'LinkedIn settings saved.');

        return $this->redirectToRoute('app_admin_linkedin');
    }

    #[Route('/admin/linkedin/share/{id}', name: 'app_admin_linkedin_share', methods: ['POST'])]
    public function share(Request $request, Post $post): Response
    {
        $this->guard($request, 'linkedin_share_' . $post->getId());

        try {
            $urn = $this->publisher->share($post, true);
            $this->addFlash($urn ? 'success' : 'info', $urn ? 'Shared on LinkedIn.' : 'Only published posts can be shared.');
        } catch (\Throwable $e) {
            $this->addFlash('error', 'LinkedIn share failed: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_linkedin');
    }

    private function guard(Request $request, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
