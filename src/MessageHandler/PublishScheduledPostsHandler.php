<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Enum\PostStatus;
use App\Message\PublishScheduledPostsMessage;
use App\Repository\PostRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class PublishScheduledPostsHandler
{
    public function __construct(
        private readonly PostRepository $postRepo,
        private readonly EntityManagerInterface $em,
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
    }
}
