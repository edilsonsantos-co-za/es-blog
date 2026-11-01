<?php

declare(strict_types=1);

namespace App\Service\Newsletter;

use App\Entity\NewsletterCampaign;
use App\Entity\NewsletterDelivery;
use App\Entity\Post;
use App\Enum\PostStatus;
use App\Enum\PostType;
use App\Message\DeliverNewsletterMessage;
use App\Message\QueueNewsletterMessage;
use App\Service\SiteConfigService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

final class NewsletterQueue
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        private readonly SiteConfigService $config,
    ) {}

    public function enabled(): bool
    {
        return $this->config->getBool('newsletter.enabled', false);
    }

    /** Call after a post's first publication is flushed; drafts/pages are never sent. */
    public function published(Post $post): void
    {
        if (!$this->enabled() || $post->getId() === null || $post->getType() !== PostType::Post
            || $post->getStatus() !== PostStatus::Published) {
            return;
        }
        if ($post->getPublishedAt() !== null && $post->getPublishedAt() > new \DateTimeImmutable()) {
            return;
        }
        $campaign = $this->em->getConnection()->transactional(function ($connection) use ($post): ?NewsletterCampaign {
            $connection->executeQuery('SELECT id FROM posts WHERE id = ? FOR UPDATE', [$post->getId()]);
            if ($connection->fetchOne('SELECT id FROM newsletter_campaigns WHERE post_id = ?', [$post->getId()]) !== false) {
                return null;
            }
            $campaign = new NewsletterCampaign();
            $campaign->post = $post;
            $campaign->subject = $post->getTitle();
            $this->em->persist($campaign);
            $this->em->flush();

            return $campaign;
        });
        if ($campaign !== null) {
            $this->dispatchCampaign($campaign);
        }
    }

    public function dispatchCampaign(NewsletterCampaign $campaign): void
    {
        $this->bus->dispatch(new QueueNewsletterMessage((int) $campaign->id), [new TransportNamesStamp(['async'])]);
    }

    public function dispatchDelivery(int $id): void
    {
        $this->bus->dispatch(new DeliverNewsletterMessage($id), [new TransportNamesStamp(['async'])]);
    }

    /** Repair interrupted queueing, never replay ambiguous/sent deliveries. */
    public function recover(): int
    {
        $count = 0;
        foreach ($this->em->getRepository(NewsletterCampaign::class)->findBy(['queuedAt' => null]) as $campaign) {
            $this->dispatchCampaign($campaign);
            ++$count;
        }
        foreach ($this->em->getRepository(NewsletterDelivery::class)->findBy(['status' => 'pending']) as $delivery) {
            $this->dispatchDelivery((int) $delivery->id);
            ++$count;
        }

        return $count;
    }
}
