<?php

declare(strict_types=1);

namespace App\Enum;

enum UserRole: string
{
    case Admin = 'admin';
    case Editor = 'editor';
    case Author = 'author';

    public function toSymfonyRole(): string
    {
        return match($this) {
            UserRole::Admin  => 'ROLE_ADMIN',
            UserRole::Editor => 'ROLE_EDITOR',
            UserRole::Author => 'ROLE_AUTHOR',
        };
    }

    public function label(): string
    {
        return match($this) {
            UserRole::Admin  => 'Administrator',
            UserRole::Editor => 'Editor',
            UserRole::Author => 'Author',
        };
    }
}
