<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\NewsletterDelivery;
use App\Enum\PostStatus;
use App\Message\DeliverNewsletterMessage;
use App\Service\Newsletter\NewsletterMailer;
use App\Service\Newsletter\NewsletterQueue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class DeliverNewsletterHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NewsletterMailer $mailer,
        private readonly NewsletterQueue $queue,
    ) {}

    public function __invoke(DeliverNewsletterMessage $message): void
    {
        if (!$this->queue->enabled()) {
            return;
        }
        $delivery = $this->em->find(NewsletterDelivery::class, $message->deliveryId);
        if ($delivery === null) {
            return;
        }
        $this->em->refresh($delivery);
        $this->em->refresh($delivery->subscriber);
        $this->em->refresh($delivery->campaign);
        $campaign = $delivery->campaign;
        if ($campaign->post !== null) {
            $this->em->refresh($campaign->post);
        }
        if ($delivery->status !== 'pending') {
            return;
        }
        if (!$delivery->subscriber->eligible($campaign->kind)
            || ($campaign->kind === 'post' && ($campaign->post === null || $campaign->post->getStatus() !== PostStatus::Published))) {
            $this->em->getConnection()->update('newsletter_deliveries', ['status' => 'skipped'], ['id' => $delivery->id, 'status' => 'pending']);
            return;
        }
        if ($campaign->post?->getPublishedAt() !== null && $campaign->post->getPublishedAt() > new \DateTimeImmutable()) {
            return;
        }
        if (!$this->mailer->ready()) {
            throw new \RuntimeException('Newsletter delivery is not configured; no message was sent.');
        }
        $subject = $campaign->subject;
        $body = $campaign->body;
        if ($campaign->post !== null) {
            $post = $campaign->post;
            $date = $post->getPublishedAt() ?? $post->getCreatedAt();
            $subject = $post->getTitle();
            $body = trim(strip_tags((string) $post->getDescription())) . "\n\nRead the post: "
                . $this->mailer->url('app_blog_post', ['year' => $date->format('Y'), 'month' => $date->format('m'), 'slug' => $post->getSlug()]);
        }
        $connection = $this->em->getConnection();
        if ($connection->executeStatement('UPDATE newsletter_deliveries SET status = ? WHERE id = ? AND status = ?', ['sending', $delivery->id, 'pending']) !== 1) {
            return;
        }
        // Re-read consent after claiming, immediately before the synchronous provider call.
        $this->em->refresh($delivery->subscriber);
        if ($campaign->post !== null) {
            $this->em->refresh($campaign->post);
        }
        if (!$delivery->subscriber->eligible($campaign->kind) || !$this->queue->enabled()
            || ($campaign->kind === 'post' && ($campaign->post === null || $campaign->post->getStatus() !== PostStatus::Published
                || ($campaign->post->getPublishedAt() !== null && $campaign->post->getPublishedAt() > new \DateTimeImmutable())))) {
            $connection->update('newsletter_deliveries', ['status' => 'skipped'], ['id' => $delivery->id]);
            return;
        }
        try {
            $this->mailer->send($delivery->subscriber, $subject, $body);
            $connection->update('newsletter_deliveries', ['status' => 'sent', 'sent_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')], ['id' => $delivery->id]);
        } catch (\Throwable $e) {
            // SMTP acknowledgement can be lost after acceptance. Never automatically resend
            // an ambiguous delivery; leave evidence for manual provider-log review.
            $connection->update('newsletter_deliveries', ['status' => 'uncertain'], ['id' => $delivery->id]);
            throw $e;
        }
    }
}
