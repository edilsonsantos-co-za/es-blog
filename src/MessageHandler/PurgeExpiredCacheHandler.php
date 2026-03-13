<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\PurgeExpiredCacheMessage;
use App\Repository\CacheEntryRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class PurgeExpiredCacheHandler
{
    public function __construct(
        private readonly CacheEntryRepository $cacheRepo,
    ) {}

    public function __invoke(PurgeExpiredCacheMessage $message): void
    {
        // purgeExpired() executes a direct DQL DELETE — no flush required.
        $this->cacheRepo->purgeExpired();
    }
}
