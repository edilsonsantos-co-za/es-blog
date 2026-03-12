<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\User;
use App\Enum\UserRole;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Controls access to user management operations.
 *
 * Attributes:
 *  USER_VIEW         — any authenticated user can view any profile
 *  USER_EDIT         — user can edit own profile; admin can edit anyone
 *  USER_DELETE       — admin only
 *  USER_CHANGE_ROLE  — admin only
 */
class UserVoter extends Voter
{
    public const VIEW        = 'USER_VIEW';
    public const EDIT        = 'USER_EDIT';
    public const DELETE      = 'USER_DELETE';
    public const CHANGE_ROLE = 'USER_CHANGE_ROLE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::DELETE, self::CHANGE_ROLE], true)
            && $subject instanceof User;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $currentUser = $token->getUser();
        if (!$currentUser instanceof User) {
            return false;
        }

        /** @var User $targetUser */
        $targetUser = $subject;

        return match ($attribute) {
            self::VIEW        => true,
            self::EDIT        => $currentUser->getId() === $targetUser->getId()
                                 || $currentUser->getRole() === UserRole::Admin,
            self::DELETE      => $currentUser->getRole() === UserRole::Admin
                                 && $currentUser->getId() !== $targetUser->getId(),
            self::CHANGE_ROLE => $currentUser->getRole() === UserRole::Admin,
            default           => false,
        };
    }
}
