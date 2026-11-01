<?php

declare(strict_types=1);

namespace App\Service\X;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final class XClient
{
    public const SCOPES = 'tweet.read tweet.write users.read offline.access';

    private const AUTH_URL = 'https://x.com/i/oauth2/authorize';
    private const TOKEN_URL = 'https://api.x.com/2/oauth2/token';
    private const API_URL = 'https://api.x.com/2';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $xClientId,
        private readonly string $xClientSecret,
        private readonly string $xRedirectUri,
    ) {}

    public function isConfigured(): bool
    {
        return $this->xClientId !== '' && $this->xClientSecret !== '' && $this->xRedirectUri !== '';
    }

    public function redirectUri(): string
    {
        return $this->xRedirectUri;
    }

    public function authorizationUrl(string $state, string $codeChallenge): string
    {
        return self::AUTH_URL . '?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $this->xClientId,
            'redirect_uri' => $this->xRedirectUri,
            'scope' => self::SCOPES,
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** @return array{access_token: string, refresh_token: ?string, expires_in: int} */
    public function exchangeCode(string $code, string $codeVerifier): array
    {
        return $this->requestToken([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->xRedirectUri,
            'code_verifier' => $codeVerifier,
        ]);
    }

    /** @return array{access_token: string, refresh_token: ?string, expires_in: int} */
    public function refreshToken(string $refreshToken): array
    {
        return $this->requestToken([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);
    }

    /** @return array{id: string, username: ?string, name: ?string} */
    public function userInfo(string $accessToken): array
    {
        $data = $this->httpClient->request('GET', self::API_URL . '/users/me', [
            'auth_bearer' => $accessToken,
            'query' => ['user.fields' => 'name,username'],
        ])->toArray();
        $user = $data['data'] ?? [];
        if (!isset($user['id'])) {
            throw new \RuntimeException('X did not return an account id.');
        }

        return [
            'id' => (string) $user['id'],
            'username' => isset($user['username']) ? (string) $user['username'] : null,
            'name' => isset($user['name']) ? (string) $user['name'] : null,
        ];
    }

    public function createPost(string $accessToken, string $text): string
    {
        $response = $this->httpClient->request('POST', self::API_URL . '/tweets', [
            'auth_bearer' => $accessToken,
            'json' => ['text' => $text],
        ]);
        $status = $response->getStatusCode();
        $body = $response->getContent(false);
        if ($status !== 201) {
            // The status code lets callers tell a definite 4xx rejection from an ambiguous failure.
            throw new \RuntimeException(sprintf('X rejected the post (HTTP %d).', $status), $status);
        }
        $data = json_decode($body, true);
        if (!is_array($data) || !isset($data['data']['id'])) {
            throw new \RuntimeException('X returned an invalid post response.');
        }

        return (string) $data['data']['id'];
    }

    /** @param array<string, string> $body
     *  @return array{access_token: string, refresh_token: ?string, expires_in: int}
     */
    private function requestToken(array $body): array
    {
        $data = $this->httpClient->request('POST', self::TOKEN_URL, [
            'auth_basic' => [$this->xClientId, $this->xClientSecret],
            'body' => $body,
        ])->toArray();
        $expiresIn = (int) ($data['expires_in'] ?? 0);
        if (!isset($data['access_token']) || $expiresIn <= 0) {
            throw new \RuntimeException('X returned an incomplete OAuth token response.');
        }

        return [
            'access_token' => (string) $data['access_token'],
            'refresh_token' => isset($data['refresh_token']) ? (string) $data['refresh_token'] : null,
            'expires_in' => $expiresIn,
        ];
    }
}
