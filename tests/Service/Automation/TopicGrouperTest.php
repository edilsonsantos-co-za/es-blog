<?php

declare(strict_types=1);

namespace App\Tests\Service\Automation;

use App\Entity\FeedItem;
use App\Entity\FeedSource;
use App\Service\Automation\TopicGrouper;
use PHPUnit\Framework\TestCase;

final class TopicGrouperTest extends TestCase
{
    public function testGroupsRelatedSourcesWithoutDuplicatesAndKeepsDifferentTopics(): void
    {
        $feed = new FeedSource('Engineering', 'https://example.com/feed');
        $items = [
            new FeedItem($feed, 'https://example.com/php-a', 'PHP runtime updates'),
            new FeedItem($feed, 'https://example.com/php-b', 'PHP production lessons'),
            new FeedItem($feed, 'https://example.com/php-a?utm_source=other', 'PHP duplicated syndication'),
            new FeedItem($feed, 'https://example.com/docker-a', 'Docker container changes'),
            new FeedItem($feed, 'https://example.com/docker-b', 'Docker deployment notes'),
            new FeedItem($feed, 'https://example.com/ai', 'AI alone'),
        ];
        $groups = (new TopicGrouper())->group($items, ['php', 'docker', 'ai'], 5);
        self::assertSame(['php', 'docker'], array_keys($groups));
        self::assertCount(2, $groups['php']);
        self::assertCount(2, $groups['docker']);
        self::assertCount(1, (new TopicGrouper())->group($items, ['php', 'docker'], 1));
        self::assertSame([], (new TopicGrouper())->group($items, ['php'], 0));
    }

    public function testConsumedAndSkippedArticlesCannotCreateAnotherDraft(): void
    {
        $feed = new FeedSource('Engineering', 'https://example.com/feed');
        $used = (new FeedItem($feed, 'https://example.com/a', 'PHP changes'))->setStatus(FeedItem::STATUS_DRAFTED);
        $skipped = (new FeedItem($feed, 'https://example.com/b', 'PHP changes'))->setStatus(FeedItem::STATUS_SKIPPED);
        $pending = new FeedItem($feed, 'https://example.com/c', 'PHP changes');
        self::assertSame([], (new TopicGrouper())->group([$used, $skipped, $pending], ['php'], 3));
    }
}
