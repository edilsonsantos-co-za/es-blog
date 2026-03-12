<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\User;
use App\Enum\UserRole;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Controls access to site-wide admin configuration.
 *
 * Attributes:
 *  CONFIG_VIEW  — editor or admin
 *  CONFIG_EDIT  — admin only
 */
class ConfigVoter extends Voter
{
    public const VIEW = 'CONFIG_VIEW';
    public const EDIT = 'CONFIG_EDIT';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        return match ($attribute) {
            self::VIEW => in_array($user->getRole(), [UserRole::Admin, UserRole::Editor], true),
            self::EDIT => $user->getRole() === UserRole::Admin,
            default    => false,
        };
    }
}
