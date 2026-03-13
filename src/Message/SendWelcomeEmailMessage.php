<?php

declare(strict_types=1);

namespace App\Message;

final class SendWelcomeEmailMessage
{
    public function __construct(
        public readonly int $userId,
        public readonly string $email,
        public readonly string $username,
    ) {}
}
