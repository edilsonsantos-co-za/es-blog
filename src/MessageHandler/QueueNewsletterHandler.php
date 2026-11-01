<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\NewsletterCampaign;
use App\Enum\PostStatus;
use App\Message\QueueNewsletterMessage;
use App\Service\Newsletter\NewsletterQueue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class QueueNewsletterHandler
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly NewsletterQueue $queue) {}

    public function __invoke(QueueNewsletterMessage $message): void
    {
        if (!$this->queue->enabled()) {
            return;
        }
        $campaign = $this->em->find(NewsletterCampaign::class, $message->campaignId);
        if ($campaign === null) {
            return;
        }
        $this->em->refresh($campaign);
        if ($campaign->queuedAt !== null
            || ($campaign->kind === 'post' && ($campaign->post === null || $campaign->post->getStatus() !== PostStatus::Published
                || ($campaign->post->getPublishedAt() !== null && $campaign->post->getPublishedAt() > new \DateTimeImmutable())))) {
            return;
        }
        $connection = $this->em->getConnection();
        $ids = $connection->transactional(function () use ($connection, $campaign): array {
            // Lock serializes duplicate campaign messages without duplicate recipients.
            $connection->executeQuery('SELECT id FROM newsletter_campaigns WHERE id = ? FOR UPDATE', [$campaign->id]);
            if ($connection->fetchOne('SELECT queued_at FROM newsletter_campaigns WHERE id = ?', [$campaign->id]) !== null) {
                return [];
            }
            $preference = $campaign->kind === 'post' ? 'posts' : 'products';
            $connection->executeStatement(
                'INSERT INTO newsletter_deliveries (campaign_id, subscriber_id, status)
                 SELECT ?, id, ? FROM newsletter_subscribers
                 WHERE confirmed_at IS NOT NULL AND confirmed_at <= ? AND unsubscribed_at IS NULL AND ' . $preference . ' = 1',
                [$campaign->id, 'pending', $campaign->createdAt->format('Y-m-d H:i:s')],
            );
            $connection->update('newsletter_campaigns', ['queued_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')], ['id' => $campaign->id]);

            return $connection->fetchFirstColumn('SELECT id FROM newsletter_deliveries WHERE campaign_id = ? AND status = ?', [$campaign->id, 'pending']);
        });
        foreach ($ids as $id) {
            $this->queue->dispatchDelivery((int) $id);
        }
    }
}
