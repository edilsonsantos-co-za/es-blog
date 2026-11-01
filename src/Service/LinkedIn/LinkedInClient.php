<?php

declare(strict_types=1);

namespace App\Service\LinkedIn;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Minimal LinkedIn client: "Sign In with LinkedIn using OpenID Connect" for the member id, and
 * "Share on LinkedIn" (w_member_social) for posts with an article card and an uploaded image.
 *
 * Both products are self-serve on a LinkedIn developer app; no partner approval is needed.
 */
final class LinkedInClient
{
    public const SCOPES = 'openid profile email w_member_social';

    private const AUTH_URL = 'https://www.linkedin.com/oauth/v2/authorization';
    private const TOKEN_URL = 'https://www.linkedin.com/oauth/v2/accessToken';
    private const API = 'https://api.linkedin.com';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $linkedInClientId,
        private readonly string $linkedInClientSecret,
        private readonly string $linkedInRedirectUri,
        private readonly string $linkedInApiVersion,
    ) {}

    public function isConfigured(): bool
    {
        return $this->linkedInClientId !== '' && $this->linkedInClientSecret !== '' && $this->linkedInRedirectUri !== '';
    }

    public function redirectUri(): string
    {
        return $this->linkedInRedirectUri;
    }

    public function authorizationUrl(string $state): string
    {
        return self::AUTH_URL . '?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $this->linkedInClientId,
            'redirect_uri' => $this->linkedInRedirectUri,
            'state' => $state,
            'scope' => self::SCOPES,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @return array{access_token: string, expires_in: int}
     */
    public function exchangeCode(string $code): array
    {
        $data = $this->httpClient->request('POST', self::TOKEN_URL, [
            'body' => [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->linkedInRedirectUri,
                'client_id' => $this->linkedInClientId,
                'client_secret' => $this->linkedInClientSecret,
            ],
        ])->toArray();

        return ['access_token' => (string) $data['access_token'], 'expires_in' => (int) ($data['expires_in'] ?? 0)];
    }

    /**
     * @return array{sub: string, name: ?string, email: ?string}
     */
    public function userInfo(string $accessToken): array
    {
        $data = $this->httpClient->request('GET', self::API . '/v2/userinfo', [
            'auth_bearer' => $accessToken,
        ])->toArray();

        return ['sub' => (string) $data['sub'], 'name' => $data['name'] ?? null, 'email' => $data['email'] ?? null];
    }

    /**
     * Uploads a PNG or JPEG and returns its image URN.
     */
    public function uploadImage(string $accessToken, string $authorUrn, string $path): string
    {
        $init = $this->httpClient->request('POST', self::API . '/rest/images?action=initializeUpload', [
            'auth_bearer' => $accessToken,
            'headers' => $this->restHeaders(),
            'json' => ['initializeUploadRequest' => ['owner' => $authorUrn]],
        ])->toArray();

        $this->httpClient->request('PUT', $init['value']['uploadUrl'], [
            'auth_bearer' => $accessToken,
            'headers' => ['Content-Type' => mime_content_type($path) ?: 'application/octet-stream'],
            'body' => fopen($path, 'r'),
        ])->getContent();

        return (string) $init['value']['image'];
    }

    /**
     * Publishes a member post with a link card. Returns the post URN.
     */
    public function sharePost(
        string $accessToken,
        string $authorUrn,
        string $commentary,
        string $url,
        string $title,
        string $description,
        ?string $imageUrn = null,
    ): string {
        $article = ['source' => $url, 'title' => mb_substr($title, 0, 200), 'description' => mb_substr($description, 0, 256)];
        if ($imageUrn !== null) {
            $article['thumbnail'] = $imageUrn;
        }

        $response = $this->httpClient->request('POST', self::API . '/rest/posts', [
            'auth_bearer' => $accessToken,
            'headers' => $this->restHeaders(),
            'json' => [
                'author' => $authorUrn,
                'commentary' => self::littleText($commentary),
                'visibility' => 'PUBLIC',
                'distribution' => [
                    'feedDistribution' => 'MAIN_FEED',
                    'targetEntities' => [],
                    'thirdPartyDistributionChannels' => [],
                ],
                'content' => ['article' => $article],
                'lifecycleState' => 'PUBLISHED',
                'isReshareDisabledByAuthor' => false,
            ],
        ]);

        $status = $response->getStatusCode();
        if ($status !== 201) {
            throw new \RuntimeException('LinkedIn rejected the post: ' . mb_substr($response->getContent(false), 0, 500), $status);
        }

        return $response->getHeaders()['x-restli-id'][0] ?? 'unknown';
    }

    /**
     * Escapes text for LinkedIn's "little text" commentary format and turns #word into hashtags.
     * Unescaped reserved characters make LinkedIn drop the rest of the post.
     */
    public static function littleText(string $text): string
    {
        $parts = preg_split('/(#[\p{L}\p{N}_]+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        $out = '';
        foreach ($parts as $part) {
            if (preg_match('/^#([\p{L}\p{N}_]+)$/u', $part, $m)) {
                $out .= '{hashtag|\#|' . $m[1] . '}';
                continue;
            }
            $out .= preg_replace('/([\\\\|{}@\[\]()<>#*_~])/u', '\\\\$1', $part);
        }

        return $out;
    }

    /** @return array<string, string> */
    private function restHeaders(): array
    {
        return [
            'LinkedIn-Version' => $this->linkedInApiVersion,
            'X-Restli-Protocol-Version' => '2.0.0',
        ];
    }
}
