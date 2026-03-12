<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\PasswordHasher\Hasher\CheckPasswordLengthTrait;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

/**
 * Handles login for users still storing legacy SHA-512 passwords from HTMLy v1.
 *
 * The v1 app hashed passwords as: sha512(password + username).
 * On successful login the Symfony Security layer calls PasswordUpgrader
 * (UserRepository::upgradePassword) to transparently re-hash to bcrypt.
 */
class LegacyPasswordHasher implements PasswordHasherInterface
{
    use CheckPasswordLengthTrait;

    public function hash(string $plainPassword): string
    {
        // New passwords are never created with this hasher — bcrypt is used instead.
        throw new \LogicException('LegacyPasswordHasher::hash() should not be called. Use the default bcrypt hasher for new passwords.');
    }

    public function verify(string $hashedPassword, string $plainPassword, ?string $salt = null): bool
    {
        if ($this->isPasswordTooLong($plainPassword)) {
            return false;
        }

        // Salt in the legacy app was the username, stored in $salt param.
        // We pass the username as salt from the custom UserPasswordHasher below.
        $legacy = hash('sha512', $plainPassword . ($salt ?? ''));

        return hash_equals($hashedPassword, $legacy);
    }

    public function needsRehash(string $hashedPassword): bool
    {
        // SHA-512 hashes are exactly 128 hex chars; bcrypt starts with $2y$.
        return !str_starts_with($hashedPassword, '$2y$') && !str_starts_with($hashedPassword, '$2a$');
    }
}
