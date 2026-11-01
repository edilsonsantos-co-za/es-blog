<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SyncLinkedInPostMessage;
use App\Repository\PostRepository;
use App\Service\LinkedIn\LinkedInPublisher;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SyncLinkedInPostHandler
{
    public function __construct(
        private readonly PostRepository $postRepo,
        private readonly LinkedInPublisher $publisher,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(SyncLinkedInPostMessage $message): void
    {
        $post = $this->postRepo->find($message->postId);
        if ($post === null || $post->getLinkedInPostUrn() !== null) {
            return;
        }
        if ($this->publisher->connection($post->getAuthor()) === null) {
            $this->logger->info('LinkedIn not connected; skipping share', ['postId' => $message->postId]);

            return;
        }

        // Errors propagate so Messenger retries; the URN claim makes retries no-ops once a send was attempted.
        $urn = $this->publisher->share($post, $message->textHash);
        if ($urn === null) {
            $this->logger->warning('LinkedIn share skipped: already claimed or edited after review', ['postId' => $message->postId]);
        } else {
            $this->logger->info('Shared post on LinkedIn', ['postId' => $message->postId, 'urn' => $urn]);
        }
    }
}
