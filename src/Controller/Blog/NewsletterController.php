<?php

declare(strict_types=1);

namespace App\Controller\Blog;

use App\Service\Newsletter\NewsletterMailer;
use App\Service\Newsletter\NewsletterSubscriptions;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

final class NewsletterController extends AbstractController
{
    public function __construct(
        private readonly NewsletterSubscriptions $subscriptions,
        private readonly NewsletterMailer $mailer,
        private readonly EntityManagerInterface $em,
        #[Autowire(service: 'limiter.newsletter_subscribe')] private readonly RateLimiterFactory $limiter,
    ) {}

    #[Route('/newsletter', name: 'app_newsletter_subscribe', methods: ['GET', 'POST'])]
    public function subscribe(Request $request): Response
    {
        $message = null;
        $sent = false;
        $status = 200;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('newsletter_subscribe', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Invalid form token.');
            }
            if (!$this->limiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
                return $this->page('newsletter/subscribe.html.twig', ['message' => 'Please wait a few minutes before trying again.', 'sent' => false, 'ready' => $this->mailer->ready()], 429);
            }
            try {
                $this->subscriptions->request($request->request->getString('email'),
                    $request->request->getString('posts') === '1', $request->request->getString('products') === '1');
                $sent = true;
            } catch (\InvalidArgumentException $e) {
                $message = $e->getMessage();
                $status = 422;
            }
        }

        return $this->page('newsletter/subscribe.html.twig', ['message' => $message, 'sent' => $sent, 'ready' => $this->mailer->ready()], $status);
    }

    #[Route('/newsletter/confirm/{token}', name: 'app_newsletter_confirm', methods: ['GET', 'POST'])]
    public function confirm(string $token, Request $request): Response
    {
        $subscriber = $this->subscriptions->confirmation($token);
        $confirmed = false;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('newsletter_confirm', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Invalid form token.');
            }
            if ($subscriber !== null) {
                $confirmed = $subscriber->confirm($token);
                $this->em->flush();
                if ($confirmed) {
                    $this->subscriptions->welcome($subscriber);
                }
            }
        }

        return $this->page('newsletter/confirm.html.twig', ['subscriber' => $subscriber, 'confirmed' => $confirmed]);
    }

    #[Route('/newsletter/unsubscribe/{token}', name: 'app_newsletter_unsubscribe', methods: ['GET', 'POST'])]
    public function unsubscribe(string $token, Request $request): Response
    {
        $subscriber = $this->subscriptions->unsubscribeToken($token);
        $saved = false;
        if ($request->isMethod('POST')) {
            $oneClick = $request->request->getString('List-Unsubscribe') === 'One-Click';
            if (!$oneClick && !$this->isCsrfTokenValid('newsletter_preferences', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Invalid form token.');
            }
            if ($subscriber !== null) {
                if ($oneClick || $request->request->getString('action') === 'unsubscribe') {
                    $subscriber->unsubscribe();
                } elseif ($subscriber->confirmedAt !== null && $subscriber->unsubscribedAt === null) {
                    $subscriber->posts = $request->request->getString('posts') === '1';
                    $subscriber->products = $request->request->getString('products') === '1';
                    $subscriber->consentUpdatedAt = new \DateTimeImmutable();
                    if (!$subscriber->posts && !$subscriber->products) {
                        $subscriber->unsubscribe();
                    }
                }
                $this->em->flush();
                $saved = true;
            }
            if ($oneClick) {
                // Idempotent RFC 8058 endpoint: bearer token authorizes unsubscribe only.
                return new Response('', 200, ['Cache-Control' => 'no-store']);
            }
        }

        return $this->page('newsletter/preferences.html.twig', ['subscriber' => $subscriber, 'saved' => $saved]);
    }

    private function page(string $template, array $data, int $status = 200): Response
    {
        return $this->render($template, $data, new Response('', $status, [
            'Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex',
        ]));
    }
}
