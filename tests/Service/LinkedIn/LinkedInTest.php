<?php

declare(strict_types=1);

namespace App\Tests\Service\LinkedIn;

use App\Service\Automation\FeedFetcher;
use App\Service\LinkedIn\LinkedInClient;
use App\Service\LinkedIn\TokenCipher;
use App\Entity\LinkedInIntegration;
use App\Entity\Post;
use App\Entity\User;
use App\Repository\LinkedInIntegrationRepository;
use App\Repository\SiteConfigRepository;
use App\Service\LinkedIn\LinkedInPublisher;
use App\Service\SiteConfigService;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Cache\CacheInterface;

final class LinkedInTest extends TestCase
{
    public function testLittleTextEscapesReservedCharactersAndKeepsHashtags(): void
    {
        self::assertSame(
            'Symfony 7.4 \(LTS\) is out \[link\] {hashtag|\#|PHP} snake\_case \@me',
            LinkedInClient::littleText('Symfony 7.4 (LTS) is out [link] #PHP snake_case @me')
        );
    }

    public function testTokenCipherRoundTripsAndRejectsOtherKeys(): void
    {
        $cipher = new TokenCipher('secret-a');
        $stored = $cipher->encrypt('AQX-token');

        self::assertStringStartsWith('enc1:', $stored);
        self::assertSame('AQX-token', $cipher->decrypt($stored));
        self::assertNull((new TokenCipher('secret-b'))->decrypt($stored));
        self::assertNull($cipher->decrypt('plain-token'));
    }

    public function testTitleMatchesScoreDouble(): void
    {
        $fetcher = (new \ReflectionClass(FeedFetcher::class))->newInstanceWithoutConstructor();

        self::assertSame(3, $fetcher->score('Laravel 13 ships', 'A new Docker image too', ['laravel', 'docker', 'aws']));
        self::assertSame(0, $fetcher->score('Phpstorm tips', 'phpunit', ['php']));
    }

    public function testTrackingParametersAreRemoved(): void
    {
        self::assertSame('https://symfony.com/blog/x', FeedFetcher::withoutTracking('https://symfony.com/blog/x?utm_source=Feed&utm_medium=feed'));
        self::assertSame('https://a.test/p?id=3', FeedFetcher::withoutTracking('https://a.test/p?id=3&utm_campaign=c'));
    }

    public function testExplicitShareRefusesEditsAndExistingClaimBeforeSending(): void
    {
        $calls = 0;
        $client = new LinkedInClient(new MockHttpClient(static function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse('', ['http_code' => 201, 'response_headers' => ['x-restli-id' => 'urn:li:share:1']]);
        }), 'id', 'secret', 'https://blog.test/cb', '202608');
        $cipher = new TokenCipher('secret');
        $integrations = $this->createStub(LinkedInIntegrationRepository::class);
        $integrations->method('findForUser')->willReturn((new LinkedInIntegration())
            ->setAccessToken($cipher->encrypt('token'))
            ->setTokenExpiresAt(new \DateTimeImmutable('+1 hour'))
            ->setUserUrn('urn:li:person:me'));
        $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $conn->executeStatement('CREATE TABLE posts (id INT PRIMARY KEY, linkedin_post_urn VARCHAR(255))');
        // Another worker already claimed the row; this copy of the post was loaded before that.
        $conn->insert('posts', ['id' => 7, 'linkedin_post_urn' => LinkedInPublisher::PENDING]);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('https://blog.test/2026/11/p');
        $publisher = new LinkedInPublisher($client, $integrations, $cipher, new SiteConfigService(
            $this->createStub(SiteConfigRepository::class),
            $this->createStub(CacheInterface::class),
        ), $urls, $em, '/nonexistent');
        $post = (new Post())->setAuthor(new User())->setSlug('p')->setTitle('Title')->setBody('b')->setPublishedAt(new \DateTimeImmutable('-1 day'));
        (new \ReflectionProperty(Post::class, 'id'))->setValue($post, 7);
        $hash = $publisher->reviewHash($post);

        self::assertNull($publisher->share($post, $publisher->reviewHash((clone $post)->setTitle('Edited'))));
        self::assertNull($publisher->share($post, $hash));
        self::assertSame(0, $calls);
    }
}
