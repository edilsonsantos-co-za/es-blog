<?php

declare(strict_types=1);

namespace App\Message;

final class PublishXPostMessage
{
    public function __construct(
        public readonly int $postId,
        /** XPublisher::reviewHash() at review time; the worker refuses if the text changed since. */
        public readonly string $textHash,
    ) {}
}
