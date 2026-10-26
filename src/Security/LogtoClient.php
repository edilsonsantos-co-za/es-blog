<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Minimal OpenID Connect client for Logto (authorization code flow with PKCE).
 *
 * The ID token comes straight from the token endpoint over the back channel, so its
 * claims (issuer, audience, expiry, nonce) are validated rather than its signature,
 * as permitted by OpenID Connect Core 3.1.3.7.
 */
final class LogtoClient
{
    public function __construct(
        private readonly HttpClientInterface $http,
        #[Autowire('%env(default::LOGTO_ENDPOINT)%')]
        private readonly ?string $endpoint,
        #[Autowire('%env(default::LOGTO_INTERNAL_ENDPOINT)%')]
        private readonly ?string $internalEndpoint,
        #[Autowire('%env(default::LOGTO_APP_ID)%')]
        private readonly ?string $appId,
        #[Autowire('%env(default::LOGTO_APP_SECRET)%')]
        private readonly ?string $appSecret,
    ) {}

    public function isEnabled(): bool
    {
        return (string) $this->endpoint !== '' && (string) $this->appId !== '' && (string) $this->appSecret !== '';
    }

    /** @return array{url: string, state: string, nonce: string, verifier: string} */
    public function authorizationRequest(string $redirectUri): array
    {
        $state = bin2hex(random_bytes(20));
        $nonce = bin2hex(random_bytes(20));
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $url = $this->publicBase() . '/oidc/auth?' . http_build_query([
            'client_id' => $this->appId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);

        return ['url' => $url, 'state' => $state, 'nonce' => $nonce, 'verifier' => $verifier];
    }

    /**
     * Exchange an authorization code and return the validated ID token claims.
     *
     * @return array<string, mixed>
     */
    public function exchangeCode(string $code, string $redirectUri, string $verifier, string $nonce): array
    {
        $response = $this->http->request('POST', $this->serverBase() . '/oidc/token', [
            'auth_basic' => [(string) $this->appId, (string) $this->appSecret],
            'body' => [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $redirectUri,
                'code_verifier' => $verifier,
            ],
            'timeout' => 10,
        ]);

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('Token exchange failed: ' . $response->getStatusCode());
        }

        $idToken = $response->toArray(false)['id_token'] ?? null;
        if (!is_string($idToken)) {
            throw new \RuntimeException('Token response has no ID token.');
        }

        $claims = $this->decodeJwtPayload($idToken);
        $issuers = [$this->publicBase() . '/oidc', $this->serverBase() . '/oidc'];
        $audience = (array) ($claims['aud'] ?? []);

        if (!in_array($claims['iss'] ?? null, $issuers, true)
            || !in_array($this->appId, $audience, true)
            || (int) ($claims['exp'] ?? 0) < time()
            || !hash_equals($nonce, (string) ($claims['nonce'] ?? ''))) {
            throw new \RuntimeException('ID token claims are not valid for this client.');
        }

        return $claims;
    }

    /** End the Logto session too, so signing out of the blog does not sign straight back in. */
    public function endSessionUrl(string $postLogoutRedirectUri): string
    {
        return $this->publicBase() . '/oidc/session/end?' . http_build_query([
            'client_id' => $this->appId,
            'post_logout_redirect_uri' => $postLogoutRedirectUri,
        ]);
    }

    private function publicBase(): string
    {
        return rtrim((string) $this->endpoint, '/');
    }

    private function serverBase(): string
    {
        return rtrim((string) ($this->internalEndpoint ?: $this->endpoint), '/');
    }

    /** @return array<string, mixed> */
    private function decodeJwtPayload(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new \RuntimeException('Malformed ID token.');
        }

        $payload = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true);
        if (!is_array($payload)) {
            throw new \RuntimeException('Malformed ID token payload.');
        }

        return $payload;
    }
}
