<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Explicit reviewed share of a post to LinkedIn.
 */
final class SyncLinkedInPostMessage
{
    public function __construct(
        public readonly int $postId,
        /** LinkedInPublisher::reviewHash() at review time; the worker refuses if the post changed since. */
        public readonly string $textHash,
    ) {}
}
