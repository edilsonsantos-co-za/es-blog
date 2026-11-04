<?php

declare(strict_types=1);

namespace App\Service\Automation;

use App\Entity\FeedItem;
use App\Entity\FeedSource;
use App\Repository\FeedItemRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Downloads a feed, scores each new article against the configured topics and stores it.
 */
final class FeedFetcher
{
    private const MAX_BYTES = 5_000_000;

    private readonly HttpClientInterface $httpClient;

    public function __construct(
        HttpClientInterface $httpClient,
        private readonly FeedParser $parser,
        private readonly FeedItemRepository $items,
        private readonly EntityManagerInterface $em,
        private readonly AutomationSettings $settings,
    ) {
        // Feed URLs come from the admin or an OPML file, so never let them reach internal services.
        $this->httpClient = new NoPrivateNetworkHttpClient($httpClient);
    }

    /**
     * @return int number of new items stored
     */
    public function fetch(FeedSource $source): int
    {
        try {
            $response = $this->httpClient->request('GET', $source->getUrl(), [
                'timeout' => 20,
                'max_redirects' => 5,
                'headers' => [
                    'User-Agent' => 'ESBlog/2 (+https://blog.edilsonsantos.co.za) feed reader',
                    'Accept' => 'application/rss+xml, application/atom+xml, application/xml;q=0.9, */*;q=0.5',
                ],
            ]);
            $body = $response->getContent();
            if (strlen($body) > self::MAX_BYTES) {
                throw new \RuntimeException('Feed is larger than 5 MB.');
            }
            $entries = $this->parser->parseFeed($body);
        } catch (\Throwable $e) {
            $source->markFetched(mb_substr($e->getMessage(), 0, 500));
            $this->em->flush();
            throw $e;
        }

        $topics = $this->settings->topics();
        $cutoff = new \DateTimeImmutable('-' . $this->settings->maxAgeDays() . ' days');
        $added = 0;
        $seen = [];

        foreach ($entries as $entry) {
            if ($entry['published'] !== null && $entry['published'] < $cutoff) {
                continue;
            }
            $entry['link'] = self::withoutTracking($entry['link']);
            $fingerprint = FeedItem::fingerprintFor($entry['link']);
            if (isset($seen[$fingerprint]) || $this->items->existsFingerprint($fingerprint)) {
                continue;
            }
            $seen[$fingerprint] = true;

            $item = (new FeedItem($source, mb_substr($entry['link'], 0, 1024), mb_substr($entry['title'], 0, 500)))
                ->setSummary($entry['summary'] ?: null)
                ->setPublishedAt($entry['published'])
                ->setScore($this->score($entry['title'], $entry['summary'], $topics));
            $this->em->persist($item);
            ++$added;
        }

        $source->markFetched();
        $this->em->flush();

        return $added;
    }

    /**
     * Topic matches in the title count double; whole words only.
     *
     * @param list<string> $topics
     */
    public function score(string $title, string $summary, array $topics): int
    {
        $score = 0;
        foreach ($topics as $topic) {
            $pattern = '/\b' . preg_quote($topic, '/') . '\b/iu';
            if (preg_match($pattern, $title)) {
                $score += 2;
            } elseif (preg_match($pattern, $summary)) {
                ++$score;
            }
        }

        return $score;
    }

    /** Drops utm_* and similar tracking parameters that feeds add to links. */
    public static function withoutTracking(string $link): string
    {
        $query = parse_url($link, PHP_URL_QUERY);
        if (!is_string($query) || $query === '') {
            return $link;
        }
        parse_str($query, $params);
        $params = array_filter(
            $params,
            static fn (string $key): bool => !preg_match('/^(utm_|fbclid$|gclid$|mc_[ce]id$)/i', $key),
            ARRAY_FILTER_USE_KEY
        );
        $base = strtok($link, '?');

        return $params === [] ? $base : $base . '?' . http_build_query($params);
    }
}
