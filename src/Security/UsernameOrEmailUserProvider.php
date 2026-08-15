<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/** @implements UserProviderInterface<User> */
final readonly class UsernameOrEmailUserProvider implements UserProviderInterface
{
    public function __construct(
        private UserRepository $users,
    ) {}

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $identifier = trim($identifier);
        $user = $this->users->findByUsername($identifier) ?? $this->users->findByEmail($identifier);

        if (!$user instanceof User) {
            $exception = new UserNotFoundException();
            $exception->setUserIdentifier($identifier);

            throw $exception;
        }

        return $user;
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof User || null === $user->getId()) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $refreshed = $this->users->find($user->getId());
        if (!$refreshed instanceof User) {
            $exception = new UserNotFoundException();
            $exception->setUserIdentifier($user->getUserIdentifier());

            throw $exception;
        }

        return $refreshed;
    }

    public function supportsClass(string $class): bool
    {
        return User::class === $class || is_subclass_of($class, User::class);
    }
}
