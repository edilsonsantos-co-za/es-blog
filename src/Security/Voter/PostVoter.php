<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Post;
use App\Entity\User;
use App\Enum\UserRole;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Controls who can create, edit, delete, and publish posts.
 *
 * Attributes:
 *  POST_VIEW    — always granted (handled at controller level, not this voter)
 *  POST_CREATE  — any logged-in author/editor/admin
 *  POST_EDIT    — author owns the post, or editor/admin
 *  POST_DELETE  — author owns the post, or admin
 *  POST_PUBLISH — editor or admin only
 */
class PostVoter extends Voter
{
    public const VIEW    = 'POST_VIEW';
    public const CREATE  = 'POST_CREATE';
    public const EDIT    = 'POST_EDIT';
    public const DELETE  = 'POST_DELETE';
    public const PUBLISH = 'POST_PUBLISH';

    protected function supports(string $attribute, mixed $subject): bool
    {
        if (!in_array($attribute, [self::VIEW, self::CREATE, self::EDIT, self::DELETE, self::PUBLISH], true)) {
            return false;
        }

        if ($attribute === self::CREATE) {
            return true; // No subject required
        }

        return $subject instanceof Post;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        return match ($attribute) {
            self::VIEW    => true,
            self::CREATE  => true, // Any authenticated user with ROLE_AUTHOR+
            self::EDIT    => $this->canEdit($subject, $user),
            self::DELETE  => $this->canDelete($subject, $user),
            self::PUBLISH => $this->canPublish($user),
            default       => false,
        };
    }

    private function canEdit(Post $post, User $user): bool
    {
        if ($user->getRole() === UserRole::Admin || $user->getRole() === UserRole::Editor) {
            return true;
        }

        return $post->getAuthor()->getId() === $user->getId();
    }

    private function canDelete(Post $post, User $user): bool
    {
        if ($user->getRole() === UserRole::Admin) {
            return true;
        }

        return $post->getAuthor()->getId() === $user->getId();
    }

    private function canPublish(User $user): bool
    {
        return in_array($user->getRole(), [UserRole::Admin, UserRole::Editor], true);
    }
}
