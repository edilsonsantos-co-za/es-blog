<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Enum\PostStatus;
use App\Message\PublishScheduledPostsMessage;
use App\Repository\PostRepository;
use App\Service\PostService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class PublishScheduledPostsHandler
{
    public function __construct(
        private readonly PostRepository $postRepo,
        private readonly PostService $postService,
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
            $this->postService->update($post, ['status' => PostStatus::Published]);
        }
    }
}
