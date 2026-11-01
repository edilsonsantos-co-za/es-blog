<?php

declare(strict_types=1);

namespace App\Tests\Service\Automation;

use App\Entity\FeedItem;
use App\Entity\FeedSource;
use App\Repository\SiteConfigRepository;
use App\Service\Automation\AutomationSettings;
use App\Service\Automation\PostDrafter;
use App\Service\SiteConfigService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PostDrafterTest extends TestCase
{
    /** @return list<FeedItem> */
    private function sources(): array
    {
        return [
            (new FeedItem(new FeedSource('PHP news', 'https://example.com/feed'), 'https://example.com/php', 'PHP changes'))->setSummary('PHP deployment considerations have changed.'),
            (new FeedItem(new FeedSource('Backend notes', 'https://other.example/feed'), 'https://other.example/php', 'PHP operations'))->setSummary('Teams should check compatibility before changing their runtime.'),
        ];
    }

    private function drafter(MockHttpClient $http, string $key = 'test-key'): PostDrafter
    {
        $repo = $this->createStub(SiteConfigRepository::class);
        $repo->method('get')->willReturnCallback(static fn (string $key, ?string $default = null): ?string => $default);
        $settings = new AutomationSettings(new SiteConfigService($repo, new ArrayAdapter()));

        return new PostDrafter($settings, $key, 'claude-opus-5-5', $http);
    }

    /** @param array<string, string> $changes */
    private function response(array $changes = []): MockResponse
    {
        $draft = array_replace([
            'title' => 'PHP changes: implications for production teams',
            'description' => 'Comparing recent PHP changes and their operational implications.',
            'kicker' => 'PHP operations',
            'body' => 'These reports approach runtime changes from different angles. Treat compatibility checks as a deployment prerequisite and distinguish confirmed changes from unresolved questions.',
            'tags' => 'php, production, deployment',
            'linkedin' => 'Two perspectives on PHP changes: compatibility and production operations deserve attention before a runtime upgrade.',
            'twitter' => 'PHP runtime changes need compatibility checks, not rushed production rollouts. Compare the evidence before upgrading.',
        ], $changes);

        return new MockResponse(json_encode([
            'id' => 'msg_test', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5',
            'container' => null, 'diagnostics' => null, 'stop_details' => null,
            'content' => [['type' => 'text', 'text' => json_encode($draft, JSON_THROW_ON_ERROR)]],
            'stop_reason' => 'end_turn', 'stop_sequence' => null,
            'usage' => ['input_tokens' => 100, 'output_tokens' => 100],
        ], JSON_THROW_ON_ERROR), ['http_code' => 200]);
    }

    public function testMissingKeyDoesNotCopySourcesOrCallTheProvider(): void
    {
        $http = new MockHttpClient(static function (): never {
            self::fail('Missing key must not contact the provider.');
        });
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ANTHROPIC_API_KEY');
        $this->drafter($http, '')->draft($this->sources(), 'php');
    }

    public function testAiSynthesisesMultipleSourcesAndApplicationAppendsAttribution(): void
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('https://api.anthropic.com/v1/messages', $url);
            $request = json_decode($options['body'], true);
            $input = json_decode($request['messages'][0]['content'], true);
            self::assertCount(2, $input['sources']);
            self::assertStringContainsString('untrusted DATA', $request['system']);

            return $this->response();
        });
        $draft = $this->drafter($http)->draft($this->sources(), 'php');
        self::assertStringContainsString('[PHP news](https://example.com/php)', $draft->body);
        self::assertStringContainsString('[Backend notes](https://other.example/php)', $draft->body);
        self::assertNotEmpty($draft->linkedin);
        self::assertLessThanOrEqual(250, mb_strlen($draft->twitter));
    }

    public function testCopiedSourceSentenceIsRejectedInsteadOfSaved(): void
    {
        $sentence = 'This distinctive source sentence is intentionally more than one hundred characters long so it cannot be reused verbatim in an original editorial draft.';
        $sources = $this->sources();
        $sources[0]->setSummary($sentence);
        $http = new MockHttpClient($this->response(['body' => $sentence]));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('repeats source wording');
        $this->drafter($http)->draft($sources, 'php');
    }
}
