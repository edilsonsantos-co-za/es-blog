<?php

declare(strict_types=1);

namespace App\Service\Newsletter;

use App\Entity\NewsletterSubscriber;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class NewsletterSubscriptions
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NewsletterMailer $mailer,
        private readonly LoggerInterface $logger,
    ) {}

    public function request(string $email, bool $posts, bool $products): void
    {
        $email = strtolower(trim($email));
        if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL) || (!$posts && !$products)) {
            throw new \InvalidArgumentException('Enter a valid email and choose at least one newsletter.');
        }
        if (!$this->mailer->ready()) {
            return;
        }
        $subscriber = $this->em->getRepository(NewsletterSubscriber::class)->findOneBy(['email' => $email]);
        if ($subscriber !== null && ($subscriber->confirmedAt !== null && $subscriber->unsubscribedAt === null
            || $subscriber->requestedAt > new \DateTimeImmutable('-1 hour'))) {
            return;
        }
        $subscriber ??= new NewsletterSubscriber($email);
        $token = bin2hex(random_bytes(32));
        $subscriber->posts = $posts;
        $subscriber->products = $products;
        $subscriber->confirmedAt = null;
        $subscriber->requestedAt = new \DateTimeImmutable();
        $subscriber->confirmationExpiresAt = new \DateTimeImmutable('+24 hours');
        $subscriber->confirmationHash = hash('sha256', $token);
        $subscriber->unsubscribeHash = hash('sha256', $this->mailer->unsubscribeToken($subscriber));
        $this->em->persist($subscriber);
        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            return;
        }
        try {
            $choices = implode(' and ', array_filter([$posts ? 'new blog posts' : null, $products ? 'product/release updates' : null]));
            $this->mailer->send($subscriber, 'Confirm your ES Blog subscription', 'You requested ' . $choices . ".\nIf this was not you, ignore this email. No subscription is active until you confirm.", $token);
        } catch (\Throwable $e) {
            // Never expose provider errors or subscriber existence to the public.
            $this->logger->error('Newsletter confirmation transport failed.', ['exception' => $e]);
        }
    }

    public const WELCOME_SUBJECT = 'Welcome to ES Blog';

    /** Body of the email a reader gets once they confirm (also used by the mail gallery). */
    public function welcomeBody(NewsletterSubscriber $subscriber): string
    {
        $choices = implode(' and ', array_filter([
            $subscriber->posts ? 'new blog posts' : null,
            $subscriber->products ? 'product and release updates' : null,
        ]));

        return "Thanks for confirming. You'll get " . $choices . " from ES Blog, written by Edilson Santos.\n\n"
            . 'Catch up on the latest posts: ' . $this->mailer->url('app_blog_index') . "\n\n"
            . 'You can change what you get, or unsubscribe, with the link at the bottom of every email.';
    }

    /** Sent once, right after a reader confirms. Delivery problems never undo the confirmation. */
    public function welcome(NewsletterSubscriber $subscriber): void
    {
        try {
            $this->mailer->send($subscriber, self::WELCOME_SUBJECT, $this->welcomeBody($subscriber));
        } catch (\Throwable $e) {
            $this->logger->error('Newsletter welcome transport failed.', ['exception' => $e]);
        }
    }

    public function confirmation(string $token): ?NewsletterSubscriber
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
            return null;
        }
        $subscriber = $this->em->getRepository(NewsletterSubscriber::class)->findOneBy(['confirmationHash' => hash('sha256', $token)]);

        return $subscriber !== null && $subscriber->confirmationExpiresAt > new \DateTimeImmutable() ? $subscriber : null;
    }

    public function unsubscribeToken(string $token): ?NewsletterSubscriber
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
            return null;
        }

        return $this->em->getRepository(NewsletterSubscriber::class)->findOneBy(['unsubscribeHash' => hash('sha256', $token)]);
    }
}
