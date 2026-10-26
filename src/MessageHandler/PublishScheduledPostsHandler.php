<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Enum\PostStatus;
use App\Message\PublishScheduledPostsMessage;
use App\Message\SyncLinkedInPostMessage;
use App\Repository\PostRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class PublishScheduledPostsHandler
{
    public function __construct(
        private readonly PostRepository $postRepo,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
    ) {}

    public function __invoke(PublishScheduledPostsMessage $message): void
    {
        $now = new \DateTimeImmutable();

        $posts = $this->postRepo->createQueryBuilder('p')
            ->where('p.status = :status')
            ->andWhere('p.publishedAt IS NOT NULL AND p.publishedAt <= :now')
            ->setParameter('status', PostStatus::Scheduled)
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();

        foreach ($posts as $post) {
            $post->setStatus(PostStatus::Published);
        }

        if (count($posts) > 0) {
            $this->em->flush();
        }

        // Newly live posts go to LinkedIn like posts published by hand.
        foreach ($posts as $post) {
            $this->bus->dispatch(new SyncLinkedInPostMessage($post->getId()));
        }
    }
}
