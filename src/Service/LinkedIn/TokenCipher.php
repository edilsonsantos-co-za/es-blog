<?php

declare(strict_types=1);

namespace App\Service\LinkedIn;

/**
 * Encrypts OAuth tokens at rest with a key derived from APP_SECRET (libsodium secretbox).
 */
final class TokenCipher
{
    private const PREFIX = 'enc1:';

    private string $key;

    public function __construct(string $appSecret)
    {
        $this->key = sodium_crypto_generichash('linkedin-token|' . $appSecret, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return self::PREFIX . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $this->key));
    }

    public function decrypt(string $stored): ?string
    {
        if (!str_starts_with($stored, self::PREFIX)) {
            return null;
        }
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key
        );

        return $plain === false ? null : $plain;
    }
}
