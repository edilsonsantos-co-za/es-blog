<?php

declare(strict_types=1);

namespace App\Service\Automation;

use Anthropic\Client;
use App\Entity\FeedItem;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Writes an original post about a feed item with Claude, or an offline skeleton when no API key is set.
 */
final class PostDrafter
{
    private const SOURCE_CHARS = 24_000;

    private readonly HttpClientInterface $httpClient;

    public function __construct(
        HttpClientInterface $httpClient,
        private readonly AutomationSettings $settings,
        private readonly LoggerInterface $logger,
        private readonly string $anthropicApiKey,
        private readonly string $automationModel,
    ) {
        $this->httpClient = new NoPrivateNetworkHttpClient($httpClient);
    }

    public function isAiEnabled(): bool
    {
        return $this->anthropicApiKey !== '';
    }

    public function model(): string
    {
        return $this->automationModel;
    }

    public function draft(FeedItem $item, bool $willPublish): DraftedPost
    {
        if (!$this->isAiEnabled()) {
            return $this->offline($item);
        }

        $client = new Client(apiKey: $this->anthropicApiKey);
        $message = $client->messages->create(
            model: $this->automationModel,
            maxTokens: 16000,
            system: $this->systemPrompt($willPublish),
            messages: [['role' => 'user', 'content' => $this->userPrompt($item)]],
            outputConfig: ['format' => DraftedPost::class, 'effort' => 'medium'],
        );

        if ($message->stopReason === 'refusal') {
            throw new \RuntimeException('Claude declined to write about this item.');
        }
        $parsed = $message->parsedOutput();
        if (!$parsed instanceof DraftedPost) {
            throw new \RuntimeException('Claude did not return a valid post: ' . json_encode($parsed));
        }

        return $parsed;
    }

    private function systemPrompt(bool $willPublish): string
    {
        $rules = [
            'You write blog posts for Edilson Santos\'s developer blog. Voice: ' . $this->settings->voice(),
            'Write original text. Never copy sentences from the source; quote at most one short phrase, in quotation marks.',
            'Credit the source by name with a Markdown link in the opening paragraph and again in a final "Source:" line.',
            'Stay accurate: only state facts that are in the source text you are given. If it describes an unpatched or developing issue, say so and do not speculate.',
            'Add value beyond a summary: what it means for teams running PHP and backend systems in production, and what to do next.',
            'Never invent personal experiences, employers, clients, quotes or numbers.',
        ];
        $rules[] = $willPublish
            ? 'This post is published without human review, so do not leave placeholders and do not write in the first person about past projects.'
            : 'The author reviews this draft before publishing. Where a personal example would help, write [ADD YOUR EXAMPLE: short hint] for him to fill in.';

        return implode("\n", $rules);
    }

    private function userPrompt(FeedItem $item): string
    {
        $published = $item->getPublishedAt()?->format('j F Y') ?? 'unknown date';

        return "Write a post about this article.\n\n"
            . "Source: {$item->getSource()->getName()}\n"
            . "Title: {$item->getTitle()}\n"
            . "URL: {$item->getLink()}\n"
            . "Published: {$published}\n\n"
            . "Feed summary:\n" . ($item->getSummary() ?: '(none)') . "\n\n"
            . "Article text (may be incomplete):\n" . $this->articleText($item->getLink());
    }

    /**
     * Best-effort plain text of the article page, so the post rests on more than the feed excerpt.
     */
    private function articleText(string $url): string
    {
        try {
            $html = $this->httpClient->request('GET', $url, [
                'timeout' => 20,
                'headers' => ['User-Agent' => 'EdFolioBlog/2 (+https://blog.edilsonsantos.co.za)'],
            ])->getContent();
        } catch (\Throwable $e) {
            $this->logger->info('Could not fetch article text', ['url' => $url, 'error' => $e->getMessage()]);

            return '(not available)';
        }

        $html = preg_replace('#<(script|style|nav|header|footer|aside|form|svg)\b[^>]*>.*?</\1>#is', ' ', $html) ?? '';
        if (preg_match('#<(article|main)\b[^>]*>(.*?)</\1>#is', $html, $m)) {
            $html = $m[2];
        }
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        return $text === '' ? '(not available)' : mb_substr($text, 0, self::SOURCE_CHARS);
    }

    private function offline(FeedItem $item): DraftedPost
    {
        $source = $item->getSource()->getName();
        $link = $item->getLink();
        $summary = $item->getSummary() ?: 'No summary in the feed.';

        return DraftedPost::fromArray([
            'title' => $item->getTitle(),
            'description' => mb_substr($summary, 0, 155),
            'kicker' => $item->getSource()->getFolder() ?: $source,
            'body' => "> **Draft written without AI.** Set `ANTHROPIC_API_KEY` to get a full draft.\n\n"
                . "[{$source}]({$link}) published **{$item->getTitle()}**.\n\n"
                . "{$summary}\n\n"
                . "## Why it matters\n\n[ADD YOUR TAKE: what this means for teams running PHP in production]\n\n"
                . "## What to do next\n\n[ADD YOUR STEPS]\n\n"
                . "Source: [{$source}]({$link})",
            'tags' => implode(', ', array_slice($this->matchingTopics($item), 0, 4)),
            'linkedin' => "{$item->getTitle()}\n\n[ADD YOUR TAKE]\n\n#PHP #BackendDevelopment #SoftwareEngineering",
        ]);
    }

    /** @return list<string> */
    private function matchingTopics(FeedItem $item): array
    {
        $haystack = $item->getTitle() . ' ' . $item->getSummary();

        return array_values(array_filter(
            $this->settings->topics(),
            static fn (string $topic): bool => (bool) preg_match('/\b' . preg_quote($topic, '/') . '\b/i', $haystack)
        ));
    }
}
