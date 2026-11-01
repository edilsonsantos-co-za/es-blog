<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\PublishXPostMessage;
use App\Repository\PostRepository;
use App\Service\X\XPublisher;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class PublishXPostHandler
{
    public function __construct(
        private readonly PostRepository $posts,
        private readonly XPublisher $publisher,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(PublishXPostMessage $message): void
    {
        $post = $this->posts->find($message->postId);
        if ($post === null || !$post->isPublished() || $this->publisher->hasShared($post)) {
            return;
        }

        // After the claim, a retry finds the share row and does nothing, so Messenger retries cannot double-post.
        $id = $this->publisher->publish($post, $message->textHash);
        if ($id === null) {
            $this->logger->warning('X share skipped: already claimed or edited after review', ['postId' => $message->postId]);

            return;
        }
        $this->logger->info('Shared reviewed post on X', ['postId' => $message->postId, 'xPostId' => $id]);
    }
}
