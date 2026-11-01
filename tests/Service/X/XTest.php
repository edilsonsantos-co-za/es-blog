<?php

declare(strict_types=1);

namespace App\Tests\Service\X;

use App\Entity\CustomField;
use App\Entity\Post;
use App\Entity\User;
use App\Entity\XIntegration;
use App\Entity\XPostShare;
use App\Enum\PostStatus;
use App\Repository\SiteConfigRepository;
use App\Repository\XIntegrationRepository;
use App\Repository\XPostShareRepository;
use App\Service\LinkedIn\TokenCipher;
use App\Service\SiteConfigService;
use App\Service\X\XClient;
use App\Service\X\XPublisher;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class XTest extends TestCase
{
    public function testAuthorizationUrlUsesPkceAndRequiredScopes(): void
    {
        $client = new XClient(new MockHttpClient(), 'client-id', 'client-secret', 'https://blog.test/admin/x/callback');
        parse_str((string) parse_url($client->authorizationUrl('csrf-state', 'pkce-challenge'), PHP_URL_QUERY), $query);

        self::assertSame('S256', $query['code_challenge_method']);
        self::assertSame('pkce-challenge', $query['code_challenge']);
        self::assertSame('csrf-state', $query['state']);
        self::assertSame(XClient::SCOPES, $query['scope']);
        self::assertSame('https://blog.test/admin/x/callback', $query['redirect_uri']);
    }

    public function testCodeExchangeAndRefreshAcceptRotatedRefreshToken(): void
    {
        $requests = [];
        $client = new XClient(new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$requests): MockResponse {
                $requests[] = [$method, $url, $options];

                return new MockResponse(json_encode([
                    'access_token' => 'new-access',
                    'refresh_token' => 'rotated-refresh',
                    'expires_in' => 7200,
                ], JSON_THROW_ON_ERROR));
            }
        ), 'client-id', 'client-secret', 'https://blog.test/callback');

        $initial = $client->exchangeCode('code-value', 'verifier-value');
        $refreshed = $client->refreshToken('old-refresh');

        self::assertSame('new-access', $initial['access_token']);
        self::assertSame('rotated-refresh', $refreshed['refresh_token']);
        parse_str($requests[0][2]['body'], $exchangeForm);
        parse_str($requests[1][2]['body'], $refreshForm);
        self::assertSame('verifier-value', $exchangeForm['code_verifier']);
        self::assertSame('old-refresh', $refreshForm['refresh_token']);
        self::assertSame('refresh_token', $refreshForm['grant_type']);
        self::assertContains(
            'Authorization: Basic ' . base64_encode('client-id:client-secret'),
            $requests[0][2]['headers'],
        );
    }

    public function testCreatePostReportsApiFailureWithoutRetryingAsSuccess(): void
    {
        $client = new XClient(new MockHttpClient(
            static fn (): MockResponse => new MockResponse('{"title":"Forbidden"}', ['http_code' => 403])
        ), 'client-id', 'client-secret', 'https://blog.test/callback');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP 403');
        $client->createPost('access-token', 'Reviewed text');
    }

    public function testPublisherDoesNotShareUnpublishedOrFuturePosts(): void
    {
        $publisher = $this->publisher();
        $post = $this->post();
        $post->setStatus(PostStatus::Draft);
        self::assertNull($publisher->publish($post, $publisher->reviewHash($post)));

        $post->setStatus(PostStatus::Published)->setPublishedAt(new \DateTimeImmutable('+1 day'));
        self::assertNull($publisher->publish($post, $publisher->reviewHash($post)));
    }

    public function testPublisherDeduplicatesPreviouslySharedPost(): void
    {
        $shares = $this->createMock(XPostShareRepository::class);
        $shares->expects(self::once())->method('findForPost')->willReturn(new XPostShare());
        $integrations = $this->createMock(XIntegrationRepository::class);
        $integrations->expects(self::never())->method('findForUser');

        self::assertNull($this->publisher($integrations, $shares)->publish($this->post(), 'hash'));
    }

    public function testPublisherAlsoDeduplicatesPostIdCustomField(): void
    {
        $post = $this->post();
        $post->addCustomField((new CustomField())
            ->setFieldKey(XPublisher::POST_ID_FIELD)
            ->setFieldValue('already-posted'));
        $integrations = $this->createMock(XIntegrationRepository::class);
        $integrations->expects(self::never())->method('findForUser');

        self::assertNull($this->publisher($integrations)->publish($post, 'hash'));
    }

    public function testEditedAfterReviewIsRefusedWithoutSending(): void
    {
        $calls = 0;
        $publisher = $this->publisher(client: $this->countingClient($calls, new MockResponse('{}', ['http_code' => 201])));
        $post = $this->post();
        $hash = $publisher->reviewHash($post);
        $post->getCustomFields()->first()->setFieldValue('Changed after review');

        self::assertNull($publisher->publish($post, $hash));
        self::assertSame(0, $calls);
    }

    public function testExistingClaimStopsConcurrentOrRetriedHandlerBeforeSending(): void
    {
        $calls = 0;
        $conn = $this->connection();
        $conn->insert('x_post_share', ['post_id' => 7, 'status' => XPostShare::PENDING, 'text_hash' => 'h', 'shared_at' => '2026-11-02 00:00:00']);
        // The repository check raced and saw nothing; only the claim insert can stop the second send.
        $publisher = $this->publisher(client: $this->countingClient($calls, new MockResponse('{"data":{"id":"1"}}', ['http_code' => 201])), conn: $conn);
        $post = $this->post();

        self::assertNull($publisher->publish($post, $publisher->reviewHash($post)));
        self::assertSame(0, $calls);
    }

    public function testAmbiguousFailureIsMarkedUnknownAndRetryDoesNotResend(): void
    {
        $calls = 0;
        $conn = $this->connection();
        $publisher = $this->publisher(client: $this->countingClient($calls, new MockResponse('', ['http_code' => 503])), conn: $conn);
        $post = $this->post();
        $hash = $publisher->reviewHash($post);

        try {
            $publisher->publish($post, $hash);
            self::fail('Ambiguous failure must surface.');
        } catch (\RuntimeException) {
        }
        self::assertSame(XPostShare::UNKNOWN, $conn->fetchOne('SELECT status FROM x_post_share WHERE post_id = 7'));

        self::assertNull($publisher->publish($post, $hash));
        self::assertSame(1, $calls);
    }

    public function testDefiniteRejectionReleasesClaim(): void
    {
        $calls = 0;
        $conn = $this->connection();
        $publisher = $this->publisher(client: $this->countingClient($calls, new MockResponse('', ['http_code' => 403])), conn: $conn);
        $post = $this->post();

        try {
            $publisher->publish($post, $publisher->reviewHash($post));
            self::fail('Rejection must surface.');
        } catch (\RuntimeException $e) {
            self::assertSame(403, $e->getCode());
        }
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM x_post_share'));
    }

    public function testPublisherRefreshesAndStoresRotatedTokensBeforePublishing(): void
    {
        $cipher = new TokenCipher('test-app-secret');
        $integration = $this->integration($cipher)
            ->setAccessToken($cipher->encrypt('expired-access'))
            ->setRefreshToken($cipher->encrypt('old-refresh'))
            ->setTokenExpiresAt(new \DateTimeImmutable('-1 minute'));
        $post = $this->post();
        $calls = [];
        $client = new XClient(new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$calls): MockResponse {
                $calls[] = [$url, $options];
                if (str_ends_with($url, '/oauth2/token')) {
                    return new MockResponse(json_encode([
                        'access_token' => 'new-access',
                        'refresh_token' => 'rotated-refresh',
                        'expires_in' => 3600,
                    ], JSON_THROW_ON_ERROR));
                }

                return new MockResponse(json_encode(['data' => ['id' => 'tweet-123']], JSON_THROW_ON_ERROR), ['http_code' => 201]);
            }
        ), 'client-id', 'client-secret', 'https://blog.test/callback');
        $conn = $this->connection();
        $publisher = $this->publisher(client: $client, cipher: $cipher, conn: $conn, integration: $integration);

        self::assertSame('tweet-123', $publisher->publish($post, $publisher->reviewHash($post)));
        self::assertSame('new-access', $cipher->decrypt($integration->getAccessToken()));
        self::assertSame('rotated-refresh', $cipher->decrypt((string) $integration->getRefreshToken()));
        self::assertSame('tweet-123', $post->getCustomFieldValue(XPublisher::POST_ID_FIELD));
        self::assertSame(['sent', 'tweet-123'], array_values($conn->fetchNumeric('SELECT status, x_post_id FROM x_post_share WHERE post_id = 7')));
        parse_str($calls[0][1]['body'], $refreshForm);
        self::assertSame('old-refresh', $refreshForm['refresh_token']);
        $posted = json_decode($calls[1][1]['body'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame("Ready text\n\nhttps://blog.test/2026/11/reviewed-post", $posted['text']);
    }

    private function publisher(
        ?XIntegrationRepository $integrations = null,
        ?XPostShareRepository $shares = null,
        ?XClient $client = null,
        ?TokenCipher $cipher = null,
        ?Connection $conn = null,
        ?XIntegration $integration = null,
    ): XPublisher {
        $cipher ??= new TokenCipher('test-app-secret');
        $repo = $this->createStub(SiteConfigRepository::class);
        $cache = $this->createStub(CacheInterface::class);
        $config = new SiteConfigService($repo, $cache);
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('https://blog.test/2026/11/reviewed-post');
        if ($integrations === null) {
            $integrations = $this->createStub(XIntegrationRepository::class);
            $integrations->method('findForUser')->willReturn($integration ?? $this->integration($cipher));
        }
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn ?? $this->connection());

        return new XPublisher(
            $client ?? new XClient(new MockHttpClient(), 'client-id', 'client-secret', 'https://blog.test/callback'),
            $integrations,
            $shares ?? $this->createStub(XPostShareRepository::class),
            $cipher,
            $config,
            $urls,
            $em,
        );
    }

    private function countingClient(int &$calls, MockResponse $response): XClient
    {
        return new XClient(new MockHttpClient(static function () use (&$calls, $response): MockResponse {
            ++$calls;

            return $response;
        }), 'client-id', 'client-secret', 'https://blog.test/callback');
    }

    private function connection(): Connection
    {
        $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $conn->executeStatement('CREATE TABLE x_post_share (id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INT NOT NULL UNIQUE, integration_id INT, status VARCHAR(16) NOT NULL, text_hash VARCHAR(64) NOT NULL, x_post_id VARCHAR(64) UNIQUE, error VARCHAR(255), shared_at DATETIME NOT NULL)');

        return $conn;
    }

    private function integration(TokenCipher $cipher): XIntegration
    {
        return (new XIntegration())
            ->setUser(new User())
            ->setXUserId('x-user')
            ->setAccessToken($cipher->encrypt('access'))
            ->setTokenExpiresAt(new \DateTimeImmutable('+1 hour'));
    }

    private function post(): Post
    {
        $post = (new Post())
            ->setAuthor(new User())
            ->setSlug('reviewed-post')
            ->setTitle('Reviewed post')
            ->setBody('Article body')
            ->setPublishedAt(new \DateTimeImmutable('-1 day'));
        (new \ReflectionProperty(Post::class, 'id'))->setValue($post, 7);
        $post->addCustomField((new CustomField())
            ->setFieldKey(XPublisher::TEXT_FIELD)
            ->setFieldValue("<b>Ready</b>\x01 text"));

        return $post;
    }
}
