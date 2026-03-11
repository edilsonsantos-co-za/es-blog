<?php

declare(strict_types=1);

namespace App\Enum;

enum PostType: string
{
    case Post    = 'post';
    case Page    = 'page';
    case Subpage = 'subpage';
    case Profile = 'profile';

    public function label(): string
    {
        return match($this) {
            PostType::Post    => 'Post',
            PostType::Page    => 'Page',
            PostType::Subpage => 'Sub-page',
            PostType::Profile => 'Profile',
        };
    }
}
