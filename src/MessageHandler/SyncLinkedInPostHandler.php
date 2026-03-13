<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SyncLinkedInPostMessage;
use App\Repository\PostRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SyncLinkedInPostHandler
{
    public function __construct(
        private readonly PostRepository $postRepo,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(SyncLinkedInPostMessage $message): void
    {
        $post = $this->postRepo->find($message->postId);

        if ($post === null) {
            return;
        }

        // Real OAuth/LinkedIn API implementation comes in a later phase.
        $this->logger->info('LinkedIn sync requested for post', [
            'postId' => $message->postId,
            'title'  => $post->getTitle(),
        ]);
    }
}
