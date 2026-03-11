<?php

declare(strict_types=1);

namespace App\Enum;

enum PostStatus: string
{
    case Published = 'published';
    case Draft     = 'draft';
    case Scheduled = 'scheduled';

    public function label(): string
    {
        return match($this) {
            PostStatus::Published => 'Published',
            PostStatus::Draft     => 'Draft',
            PostStatus::Scheduled => 'Scheduled',
        };
    }

    public function isPublic(): bool
    {
        return $this === PostStatus::Published;
    }
}
