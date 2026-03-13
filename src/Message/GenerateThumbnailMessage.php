<?php

declare(strict_types=1);

namespace App\Message;

final class GenerateThumbnailMessage
{
    public function __construct(
        public readonly string $imagePath, // absolute path
        public readonly int $width,
        public readonly int $height,
    ) {}
}
