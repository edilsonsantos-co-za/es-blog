<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\LinkedInIntegration;
use App\Entity\Post;
use App\Entity\User;
use App\Enum\PostStatus;
use App\Message\SyncLinkedInPostMessage;
use App\Repository\LinkedInIntegrationRepository;
use App\Repository\PostRepository;
use App\Service\LinkedIn\LinkedInClient;
use App\Service\LinkedIn\LinkedInPublisher;
use App\Service\LinkedIn\TokenCipher;
use App\Security\Voter\PostVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Connects a LinkedIn profile for the current blog author.
 */
#[IsGranted('ROLE_AUTHOR')]
class LinkedInController extends AbstractController
{
    private const STATE_KEY = 'linkedin_oauth_state';

    public function __construct(
        private readonly LinkedInClient $client,
        private readonly LinkedInPublisher $publisher,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('/admin/linkedin', name: 'app_admin_linkedin', methods: ['GET'])]
    public function index(PostRepository $posts): Response
    {
        $user = $this->currentUser();
        $integration = $this->publisher->connection($user);

        return $this->render('admin/linkedin/index.html.twig', [
            'configured' => $this->client->isConfigured(),
            'redirect_uri' => $this->client->redirectUri(),
            'scopes' => LinkedInClient::SCOPES,
            'integration' => $integration,
            'token_valid' => $integration !== null && $this->publisher->accessToken($integration) !== null,
            'shared' => $posts->createQueryBuilder('p')
                ->where('p.author = :author AND p.linkedInPostUrn IS NOT NULL')
                ->setParameter('author', $user)
                ->orderBy('p.linkedInSharedAt', 'DESC')
                ->setMaxResults(10)->getQuery()->getResult(),
            'unshared' => $posts->createQueryBuilder('p')
                ->where('p.author = :author AND p.status = :published AND p.linkedInPostUrn IS NULL')
                ->andWhere('(p.publishedAt IS NULL OR p.publishedAt <= :now)')
                ->setParameter('author', $user)
                ->setParameter('published', PostStatus::Published)
                ->setParameter('now', new \DateTime())
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
        } catch (\Throwable) {
            $this->addFlash('error', 'Could not finish connecting LinkedIn: ' . $e->getMessage());

            return $this->redirectToRoute('app_admin_linkedin');
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        $existing = $integrations->findByLinkedInUserId($member['sub']);
        if ($existing !== null && $existing->getUser()->getId() !== $user->getId()) {
            $this->addFlash('error', 'That LinkedIn profile is already connected to another blog author.');

            return $this->redirectToRoute('app_admin_linkedin');
        }
        $integration = $integrations->findForUser($user) ?? new LinkedInIntegration();
        $integration
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
        $integration = $integrations->findForUser($this->currentUser());
        if ($integration !== null) {
            $this->em->remove($integration);
            $this->em->flush();
        }
        $this->addFlash('success', 'LinkedIn disconnected. Posts will no longer be shared.');

        return $this->redirectToRoute('app_admin_linkedin');
    }

    #[Route('/admin/linkedin/share/{id}', name: 'app_admin_linkedin_share', methods: ['POST'])]
    public function share(Request $request, Post $post, MessageBusInterface $bus): Response
    {
        $this->denyAccessUnlessGranted(PostVoter::EDIT, $post);
        $this->guard($request, 'linkedin_share_' . $post->getId());

        try {
            if (!$post->isPublished()) {
                $this->addFlash('error', 'Only currently published posts can be shared on LinkedIn.');
            } elseif ($post->getLinkedInPostUrn() !== null) {
                $this->addFlash('info', 'This post has already been shared (or a share is in progress) on LinkedIn.');
            } elseif ($this->publisher->connection($post->getAuthor()) === null) {
                $this->addFlash('error', 'Connect LinkedIn for this post author before sharing.');
            } else {
                $bus->dispatch(new SyncLinkedInPostMessage((int) $post->getId(), $this->publisher->reviewHash($post)));
                $this->addFlash('success', 'Reviewed post queued for LinkedIn.');
            }
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Could not queue the LinkedIn share.');
        }

        return $this->redirectToRoute('app_admin_post_edit', ['id' => $post->getId()]);
    }

    private function guard(Request $request, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
