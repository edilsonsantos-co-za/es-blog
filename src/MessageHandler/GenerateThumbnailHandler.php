<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\GenerateThumbnailMessage;
use App\Service\MediaService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class GenerateThumbnailHandler
{
    public function __construct(
        private readonly MediaService $mediaService,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(GenerateThumbnailMessage $message): void
    {
        try {
            // MediaService::thumbnail() accepts absolute src path + optional width/height
            $this->mediaService->thumbnail($message->imagePath, $message->width, $message->height);
        } catch (\Throwable $e) {
            $this->logger->error('GenerateThumbnailHandler failed', [
                'imagePath' => $message->imagePath,
                'width'     => $message->width,
                'height'    => $message->height,
                'error'     => $e->getMessage(),
            ]);
        }
    }
}
