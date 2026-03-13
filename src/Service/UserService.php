<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Enum\UserRole;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Handles user lifecycle: registration, profile updates, password changes, deletion.
 * The Security firewall handles login — this service handles admin CRUD.
 */
final class UserService
{
    public function __construct(
        private readonly UserRepository $repo,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly EntityManagerInterface $em,
    ) {}

    /** @return User[] */
    public function findAll(): array
    {
        return $this->repo->findAll();
    }

    public function findByUsername(string $username): ?User
    {
        return $this->repo->findByUsername($username);
    }

    public function findById(int $id): ?User
    {
        return $this->repo->find($id);
    }

    public function create(
        string $username,
        string $plainPassword,
        UserRole $role = UserRole::Author,
        ?string $email = null,
    ): User {
        $user = new User();
        $user->setUsername($username);
        $user->setEmail($email);
        $user->setRole($role);
        $user->setPassword($this->hasher->hashPassword($user, $plainPassword));

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * Update a user's profile fields (non-security fields).
     *
     * @param array<string, mixed> $data
     */
    public function updateProfile(User $user, array $data): User
    {
        foreach (['email', 'about', 'avatar', 'facebook', 'twitter', 'instagram', 'bluesky', 'linkedin', 'github'] as $field) {
            if (array_key_exists($field, $data)) {
                $setter = 'set' . ucfirst($field);
                $user->$setter($data[$field] !== '' ? (string) $data[$field] : null);
            }
        }

        $this->em->flush();

        return $user;
    }

    public function changePassword(User $user, string $plainPassword): void
    {
        $user->setPassword($this->hasher->hashPassword($user, $plainPassword));
        $this->em->flush();
    }

    public function changeRole(User $user, UserRole $role): void
    {
        $user->setRole($role);
        $this->em->flush();
    }

    public function delete(User $user): void
    {
        $this->em->remove($user);
        $this->em->flush();
    }

    public function usernameExists(string $username): bool
    {
        return $this->repo->findByUsername($username) !== null;
    }

    public function emailExists(string $email, ?User $excluding = null): bool
    {
        $found = $this->repo->findByEmail($email);
        if ($found === null) {
            return false;
        }

        return $excluding === null || $found->getId() !== $excluding->getId();
    }
}
