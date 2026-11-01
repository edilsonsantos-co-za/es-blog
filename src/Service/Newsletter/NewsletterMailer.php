<?php

declare(strict_types=1);

namespace App\Service\Newsletter;

use App\Entity\NewsletterSubscriber;
use App\Service\SiteConfigService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

final class NewsletterMailer
{
    public function __construct(
        private readonly TransportInterface $transport,
        private readonly SiteConfigService $config,
        private readonly UrlGeneratorInterface $urls,
        private readonly Environment $twig,
        #[Autowire('%env(MAILER_DSN)%')] private readonly string $mailerDsn,
        private readonly string $appSecret,
    ) {}

    public function ready(): bool
    {
        $base = (string) $this->config->get('site.url', '');
        return self::deliverableDsn($this->mailerDsn)
            && filter_var($this->config->get('newsletter.from', ''), FILTER_VALIDATE_EMAIL) !== false
            && filter_var($base, FILTER_VALIDATE_URL) !== false
            && parse_url($base, PHP_URL_SCHEME) === 'https'
            && parse_url($base, PHP_URL_USER) === null
            && parse_url($base, PHP_URL_PASS) === null;
    }

    public static function deliverableDsn(string $dsn): bool
    {
        // Fail closed for null/native transports, including mixed failover configurations.
        return !preg_match('~(?:null|native|sendmail)://~i', $dsn)
            && preg_match('~^(?:smtps?://|[a-z0-9_-]+\+(?:api|smtp)://)~i', $dsn) === 1;
    }

    public function unsubscribeToken(NewsletterSubscriber $subscriber): string
    {
        return hash_hmac('sha256', 'newsletter-unsubscribe:' . $subscriber->email, $this->appSecret);
    }

    public function url(string $route, array $params = []): string
    {
        return rtrim((string) $this->config->get('site.url', ''), '/') . $this->urls->generate($route, $params);
    }

    public function send(NewsletterSubscriber $subscriber, string $subject, string $body, ?string $confirmation = null): void
    {
        if (!$this->ready()) {
            throw new \RuntimeException('Newsletter requires a real SMTP/API transport, newsletter.from and an HTTPS site.url.');
        }
        $unsubscribe = $this->url('app_newsletter_unsubscribe', ['token' => $this->unsubscribeToken($subscriber)]);
        $confirm = $confirmation === null ? null : $this->url('app_newsletter_confirm', ['token' => $confirmation]);
        $text = $body . ($confirm === null ? '' : "\n\nConfirm your choices (expires in 24 hours): " . $confirm)
            . "\n\nUnsubscribe or manage preferences: " . $unsubscribe;
        $email = (new Email())->from((string) $this->config->get('newsletter.from'))
            ->to($subscriber->email)->subject($subject)->text($text)
            ->html($this->twig->render('newsletter/email.html.twig', [
                'subject' => $subject, 'body' => $body, 'confirmation' => $confirm, 'unsubscribe' => $unsubscribe,
            ]));
        $email->getHeaders()->addTextHeader('List-Unsubscribe', '<' . $unsubscribe . '>');
        $email->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
        // Direct use of the existing transport keeps the final eligibility check at actual delivery,
        // even if the application's general Mailer is configured to queue rendered emails.
        $this->transport->send($email);
    }
}
