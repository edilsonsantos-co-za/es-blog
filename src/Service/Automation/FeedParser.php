<?php

declare(strict_types=1);

namespace App\Service\Automation;

/**
 * Parses RSS 2.0, RSS 1.0 (RDF) and Atom documents, and OPML subscription lists.
 */
final class FeedParser
{
    /**
     * @return list<array{title: string, link: string, summary: string, published: ?\DateTimeImmutable}>
     */
    public function parseFeed(string $xml): array
    {
        $doc = $this->load($xml);
        $items = [];

        // RSS 2.0 <channel><item>, RSS 1.0 <item> at the root, Atom <entry>
        $nodes = $doc->xpath('//*[local-name()="item" or local-name()="entry"]') ?: [];
        foreach ($nodes as $node) {
            $title = $this->text($node, 'title');
            $link = $this->link($node);
            if ($title === '' || $link === '') {
                continue;
            }
            $summary = $this->text($node, 'description')
                ?: $this->text($node, 'summary')
                ?: $this->text($node, 'encoded')
                ?: $this->text($node, 'content');

            $items[] = [
                'title' => html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5),
                'link' => $link,
                'summary' => $this->plain($summary),
                'published' => $this->date($this->text($node, 'pubDate') ?: $this->text($node, 'published')
                    ?: $this->text($node, 'updated') ?: $this->text($node, 'date')),
            ];
        }

        return $items;
    }

    /**
     * @return list<array{name: string, url: string, folder: ?string}>
     */
    public function parseOpml(string $xml): array
    {
        $doc = $this->load($xml);
        $feeds = [];
        foreach ($doc->xpath('//outline[@xmlUrl]') ?: [] as $outline) {
            $parent = $outline->xpath('parent::outline');
            $folder = $parent ? (string) ($parent[0]['title'] ?: $parent[0]['text']) : null;
            $url = trim((string) $outline['xmlUrl']);
            if (!preg_match('#^https?://#i', $url)) {
                continue;
            }
            $feeds[] = [
                'name' => trim((string) ($outline['title'] ?: $outline['text'])) ?: (parse_url($url, PHP_URL_HOST) ?: $url),
                'url' => $url,
                'folder' => $folder ?: null,
            ];
        }

        return $feeds;
    }

    private function load(string $xml): \SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        // LIBXML_NONET: never fetch external entities or DTDs named in a feed
        $doc = simplexml_load_string(trim($xml), \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($doc === false) {
            throw new \InvalidArgumentException('Not a valid XML document.');
        }

        return $doc;
    }

    private function text(\SimpleXMLElement $node, string $name): string
    {
        $match = $node->xpath('./*[local-name()="' . $name . '"]');

        return $match ? trim((string) $match[0]) : '';
    }

    private function link(\SimpleXMLElement $node): string
    {
        foreach ($node->xpath('./*[local-name()="link"]') ?: [] as $link) {
            $href = (string) $link['href'];
            $rel = (string) $link['rel'];
            if ($href !== '' && ($rel === '' || $rel === 'alternate')) {
                return trim($href);
            }
            if ($href === '' && trim((string) $link) !== '') {
                return trim((string) $link);
            }
        }
        $guid = $this->text($node, 'guid');

        return preg_match('#^https?://#', $guid) ? $guid : '';
    }

    private function plain(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        return mb_substr($text, 0, 2000);
    }

    private function date(string $value): ?\DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }
}
