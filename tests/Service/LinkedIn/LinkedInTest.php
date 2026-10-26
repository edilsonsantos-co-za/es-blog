<?php

declare(strict_types=1);

namespace App\Tests\Service\LinkedIn;

use App\Service\Automation\FeedFetcher;
use App\Service\LinkedIn\LinkedInClient;
use App\Service\LinkedIn\TokenCipher;
use PHPUnit\Framework\TestCase;

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
}
