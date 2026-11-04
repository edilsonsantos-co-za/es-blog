<?php

declare(strict_types=1);

namespace App\Service\Automation;

use App\Service\SiteConfigService;

/**
 * Typed access to the feed automation settings stored in the `config` table.
 */
final class AutomationSettings
{
    public const MODE_DRAFT = 'draft';
    public const MODE_PUBLISH = 'publish';

    public const DEFAULT_TOPICS = 'php, symfony, laravel, api, docker, aws, security, automation, ai, devops, mysql, testing';

    public function __construct(private readonly SiteConfigService $config) {}

    public function isEnabled(): bool { return $this->config->getBool('automation.enabled'); }

    public const DIGEST_DAILY = 'daily';
    public const DIGEST_WEEKLY = 'weekly';

    /** Each run is a digest of the articles collected over the last day or week */
    public function digest(): string
    {
        return $this->config->get('automation.digest') === self::DIGEST_WEEKLY ? self::DIGEST_WEEKLY : self::DIGEST_DAILY;
    }

    public function periodDays(): int { return $this->digest() === self::DIGEST_WEEKLY ? 7 : 1; }

    public function intervalHours(): int { return $this->periodDays() * 24; }

    /** Most drafts one digest writes (Claude may write fewer when the period is quiet) */
    public function postsPerRun(): int { return min(5, max(0, $this->config->getInt('automation.posts_per_run', 5))); }

    public function maxAgeDays(): int { return max(1, $this->config->getInt('automation.max_age_days', 7)); }

    public function minScore(): int { return max(0, $this->config->getInt('automation.min_score', 0)); }

    /** AI output always requires editorial review, including previously configured publish mode. */
    public function publishMode(): string
    {
        return self::MODE_DRAFT;
    }

    public function categorySlug(): ?string { return $this->config->get('automation.category') ?: null; }

    /** @return list<string> */
    public function topics(): array
    {
        $raw = $this->config->get('automation.topics') ?: self::DEFAULT_TOPICS;

        return array_values(array_filter(array_map(
            static fn (string $t): string => strtolower(trim($t)),
            explode(',', $raw)
        )));
    }

    public function topicsString(): string { return implode(', ', $this->topics()); }

    public function voice(): string
    {
        return $this->config->get('automation.voice') ?: 'Practical, direct and friendly backend developer (PHP, Symfony, Laravel, APIs, Docker, AWS) '
            . 'based in Johannesburg. Shares lessons from shipping production systems. British English. No hype, no emojis.';
    }

    public function lastRunAt(): ?\DateTimeImmutable
    {
        $value = $this->config->get('automation.last_run_at');

        return $value ? new \DateTimeImmutable($value) : null;
    }

    public function nextRunAt(): ?\DateTimeImmutable
    {
        if (!$this->isEnabled()) {
            return null;
        }
        $last = $this->lastRunAt();

        return $last === null ? new \DateTimeImmutable() : $last->modify('+' . $this->intervalHours() . ' hours');
    }

    public function isDue(\DateTimeImmutable $now = new \DateTimeImmutable()): bool
    {
        $next = $this->nextRunAt();

        return $next !== null && $next <= $now;
    }

    public function markRun(\DateTimeImmutable $at): void
    {
        $this->config->set('automation.last_run_at', $at->format(\DateTimeInterface::ATOM));
    }

    /** @param array<string, string|null> $values */
    public function save(array $values): void
    {
        $this->config->setBulk($values);
    }
}
