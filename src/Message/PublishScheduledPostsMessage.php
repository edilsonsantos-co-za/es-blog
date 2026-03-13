<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Emitted by a scheduled task to trigger checking and publishing
 * all posts whose publishedAt <= now and status = scheduled.
 */
final class PublishScheduledPostsMessage
{
}
