<?php

declare(strict_types=1);

namespace App\Tests\Service\Automation;

use App\Service\Automation\FeedParser;
use PHPUnit\Framework\TestCase;

final class FeedParserTest extends TestCase
{
    public function testParsesRss(): void
    {
        $items = (new FeedParser())->parseFeed(<<<'XML'
            <?xml version="1.0"?>
            <rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/"><channel><title>Symfony</title>
              <item><title>Symfony 7.4 &amp; you</title><link>https://symfony.com/blog/74</link>
                <description><![CDATA[<p>New <b>LTS</b> release.</p>]]></description>
                <pubDate>Mon, 26 Oct 2026 10:00:00 +0200</pubDate></item>
              <item><title></title><link>https://symfony.com/blog/empty</link></item>
            </channel></rss>
            XML);

        self::assertCount(1, $items);
        self::assertSame('Symfony 7.4 & you', $items[0]['title']);
        self::assertSame('https://symfony.com/blog/74', $items[0]['link']);
        self::assertSame('New LTS release.', $items[0]['summary']);
        self::assertSame('2026-10-26 08:00', $items[0]['published']?->format('Y-m-d H:i'));
    }

    public function testParsesAtomAlternateLink(): void
    {
        $items = (new FeedParser())->parseFeed(<<<'XML'
            <feed xmlns="http://www.w3.org/2005/Atom"><title>PHP</title>
              <entry><title>PHP 8.5.2 released</title>
                <link rel="replies" href="https://php.net/comments"/>
                <link rel="alternate" href="https://php.net/releases/8.5.2"/>
                <summary>Security fixes.</summary><updated>2026-10-20T12:00:00Z</updated></entry>
            </feed>
            XML);

        self::assertSame('https://php.net/releases/8.5.2', $items[0]['link']);
        self::assertSame('Security fixes.', $items[0]['summary']);
    }

    public function testParsesFeederOpmlWithFolders(): void
    {
        $feeds = (new FeedParser())->parseOpml(<<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <opml version="1.0"><head><title>Feeder</title></head><body>
              <outline text="PHP" title="PHP">
                <outline type="rss" text="Laravel News" title="Laravel News" xmlUrl="https://feed.laravel-news.com/" htmlUrl="https://laravel-news.com"/>
              </outline>
              <outline type="rss" text="GitHub Blog" xmlUrl="https://github.blog/feed/"/>
              <outline type="rss" text="Bad" xmlUrl="file:///etc/passwd"/>
            </body></opml>
            XML);

        self::assertSame([
            ['name' => 'Laravel News', 'url' => 'https://feed.laravel-news.com/', 'folder' => 'PHP'],
            ['name' => 'GitHub Blog', 'url' => 'https://github.blog/feed/', 'folder' => null],
        ], $feeds);
    }

    public function testRejectsInvalidXml(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new FeedParser())->parseOpml('not xml');
    }
}
