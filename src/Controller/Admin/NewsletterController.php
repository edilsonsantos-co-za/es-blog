<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\NewsletterCampaign;
use App\Entity\NewsletterSubscriber;
use App\Service\Newsletter\NewsletterMailer;
use App\Service\Newsletter\NewsletterQueue;
use App\Service\SiteConfigService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_EDITOR')]
final class NewsletterController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SiteConfigService $config,
        private readonly NewsletterMailer $mailer,
        private readonly NewsletterQueue $queue,
    ) {}

    #[Route('/admin/newsletter', name: 'app_admin_newsletter', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $repo = $this->em->getRepository(NewsletterSubscriber::class);

        return $this->render('admin/newsletter/index.html.twig', [
            'subscribers' => $repo->findBy([], ['requestedAt' => 'DESC'], 50, ($page - 1) * 50),
            'total' => $repo->count([]), 'page' => $page,
            'ready' => $this->mailer->ready(), 'enabled' => $this->queue->enabled(),
            'sender' => $this->config->get('newsletter.from', ''),
            'campaigns' => $this->em->getRepository(NewsletterCampaign::class)->findBy([], ['createdAt' => 'DESC'], 20),
            'deliveries' => $this->em->getConnection()->fetchAllAssociative('SELECT status, COUNT(*) AS total FROM newsletter_deliveries GROUP BY status'),
        ], new Response('', 200, ['Cache-Control' => 'no-store']));
    }

    #[Route('/admin/newsletter/settings', name: 'app_admin_newsletter_settings', methods: ['POST'])]
    public function settings(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $this->guard($request, 'newsletter_settings');
        $sender = trim($request->request->getString('sender'));
        if ($sender !== '' && !filter_var($sender, FILTER_VALIDATE_EMAIL)) {
            $this->addFlash('error', 'Enter a valid sender email.');
        } else {
            $this->config->set('newsletter.from', $sender);
            $enable = $request->request->getString('enabled') === '1';
            $this->config->set('newsletter.enabled', $enable && $this->mailer->ready() ? '1' : '0');
            $this->addFlash($enable && !$this->mailer->ready() ? 'error' : 'success',
                $enable && !$this->mailer->ready()
                    ? 'Delivery remains disabled: configure a real SMTP/API transport, sender and HTTPS site URL first.'
                    : 'Newsletter settings saved.');
        }

        return $this->redirectToRoute('app_admin_newsletter');
    }

    #[Route('/admin/newsletter/{id}/unsubscribe', name: 'app_admin_newsletter_disable', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function disable(int $id, Request $request): Response
    {
        $this->guard($request, 'newsletter_disable_' . $id);
        $subscriber = $this->em->find(NewsletterSubscriber::class, $id) ?? throw $this->createNotFoundException();
        $subscriber->unsubscribe();
        $this->em->flush();
        $this->addFlash('success', 'Subscriber disabled. Pending deliveries will recheck this choice.');

        return $this->redirectToRoute('app_admin_newsletter');
    }

    private function guard(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid form token.');
        }
    }
}
