<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * Wraps Symfony's normal password hasher and adds transparent legacy SHA-512
 * upgrade: if a user still has a v1 hash, we verify it and immediately re-hash
 * to bcrypt so the legacy hasher is never needed again.
 */
class LegacyPasswordMigrator
{
    private LegacyPasswordHasher $legacyHasher;

    public function __construct(
        private readonly PasswordHasherFactoryInterface $hasherFactory,
        private readonly UserRepository $userRepository,
    ) {
        $this->legacyHasher = new LegacyPasswordHasher();
    }

    /**
     * Returns true if the plain password matches, upgrading legacy hashes automatically.
     */
    public function verify(PasswordAuthenticatedUserInterface $user, string $plainPassword): bool
    {
        $stored = $user->getPassword();

        // Already bcrypt — use normal hasher.
        if (!$this->legacyHasher->needsRehash($stored)) {
            $hasher = $this->hasherFactory->getPasswordHasher($user);

            return $hasher->verify($stored, $plainPassword);
        }

        // Legacy SHA-512 — verify with username as salt.
        $username = $user instanceof User ? $user->getUsername() : '';
        if (!$this->legacyHasher->verify($stored, $plainPassword, $username)) {
            return false;
        }

        // Upgrade: re-hash to bcrypt and persist.
        $newHash = $this->hasherFactory->getPasswordHasher($user)->hash($plainPassword);
        if ($this->userRepository instanceof PasswordUpgraderInterface) {
            $this->userRepository->upgradePassword($user, $newHash);
        }

        return true;
    }
}
