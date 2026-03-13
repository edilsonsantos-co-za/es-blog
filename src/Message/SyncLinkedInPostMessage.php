<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Dispatched when a post is published and LinkedIn sync is enabled.
 */
final class SyncLinkedInPostMessage
{
    public function __construct(
        public readonly int $postId,
    ) {}
}
