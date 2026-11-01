<?php

declare(strict_types=1);

namespace App\Service\Automation;

use App\Entity\FeedItem;

final class TopicGrouper
{
    /** @param list<FeedItem> $items
     *  @param list<string> $topics
     *  @return array<string, list<FeedItem>>
     */
    public function group(array $items, array $topics, int $limit): array
    {
        $groups = [];
        $used = [];
        // ponytail: configured keyword groups, semantic clustering when topic precision needs it.
        foreach ($topics as $topic) {
            $matches = [];
            foreach ($items as $item) {
                $fingerprint = $item->getFingerprint();
                if (isset($used[$fingerprint]) || isset($matches[$fingerprint]) || $item->getPost() !== null
                    || $item->getStatus() !== FeedItem::STATUS_NEW) {
                    continue;
                }
                if (preg_match('/\b' . preg_quote($topic, '/') . '\b/iu', $item->getTitle() . ' ' . $item->getSummary())) {
                    $matches[$fingerprint] = $item;
                }
                if (count($matches) === 4) {
                    break;
                }
            }
            if (count($matches) < 2) {
                continue;
            }
            $groups[$topic] = array_values($matches);
            foreach ($matches as $fingerprint => $item) {
                $used[$fingerprint] = true;
            }
            if (count($groups) >= max(0, $limit)) {
                break;
            }
        }

        return $limit <= 0 ? [] : $groups;
    }
}
