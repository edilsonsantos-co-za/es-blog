<?php

declare(strict_types=1);

namespace App\Service\Automation;

use App\Entity\AutomationRun;
use App\Entity\FeedItem;
use App\Entity\Post;
use App\Entity\User;
use App\Enum\PostStatus;
use App\Enum\UserRole;
use App\Repository\CategoryRepository;
use App\Repository\FeedItemRepository;
use App\Repository\FeedSourceRepository;
use App\Repository\UserRepository;
use App\Service\PostService;
use App\Service\SlugService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Reads feeds, then writes the daily or weekly digest: Claude groups every article collected in the period by shared
 * topic or concern and writes one review-only draft per topic (up to five), each consolidating several articles.
 * Articles a digest leaves out are marked skipped, so the next digest starts from fresh articles.
 */
final class AutomationRunner
{
    public function __construct(
        private readonly AutomationSettings $settings,
        private readonly FeedSourceRepository $sources,
        private readonly FeedItemRepository $items,
        private readonly FeedFetcher $fetcher,
        private readonly PostDrafter $drafter,
        private readonly BannerGenerator $banners,
        private readonly PostService $posts,
        private readonly SlugService $slugs,
        private readonly CategoryRepository $categories,
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param int|null $postLimit override the "posts per run" setting (0 = only read feeds)
     */
    public function run(string $trigger, ?int $postLimit = null, ?User $author = null): AutomationRun
    {
        $this->lock();
        try {
            return $this->runLocked($trigger, $postLimit, $author);
        } finally {
            $this->unlock();
        }
    }

    private function runLocked(string $trigger, ?int $postLimit, ?User $author): AutomationRun
    {
        $run = new AutomationRun($trigger);
        $this->em->persist($run);
        $this->em->flush();

        foreach ($this->sources->findEnabled() as $source) {
            try {
                $added = $this->fetcher->fetch($source);
                $run->addFeedRead($added);
                $run->addLog(sprintf('%s: %d new', $source->getName(), $added));
            } catch (\Throwable $e) {
                $run->addLog(sprintf('%s: %s', $source->getName(), $e->getMessage()), true);
            }
        }

        $limit = max(0, min(5, $postLimit ?? $this->settings->postsPerRun()));
        if ($limit > 0 && !$this->drafter->isAiEnabled()) {
            $run->addLog('ANTHROPIC_API_KEY is missing. Sources were collected but no placeholder or copied drafts were created.', true);
        } elseif ($limit > 0) {
            $this->digest($run, $limit, $author);
        }

        $run->finish();
        $this->settings->markRun($run->getStartedAt());
        $this->em->flush();

        return $run;
    }

    private function digest(AutomationRun $run, int $limit, ?User $author): void
    {
        $items = $this->periodItems();
        $period = $this->settings->digest();
        $run->addLog(sprintf('%s digest: %d articles collected since the last digest.', ucfirst($period), count($items)));
        if (count($items) < 2) {
            $run->addLog('Waiting for at least two articles in the period. No single-source posts were created.');

            return;
        }
        try {
            $clusters = $this->drafter->plan($items, $limit, $period);
        } catch (\Throwable $e) {
            $run->addLog('Claude could not plan the digest; articles remain pending for the next run.', true);
            $this->logger->error('Automation could not plan a digest', ['exception' => $e]);

            return;
        }
        if ($clusters === []) {
            $run->addLog('No topic had two or more related articles. Articles remain pending.');

            return;
        }
        $failed = false;
        foreach ($clusters as $cluster) {
            try {
                $post = $this->createSynthesis($cluster['items'], $cluster['topic'], $author, $cluster['angle']);
                $run->addPostCreated();
                $run->addLog(sprintf('Draft #%d from %d sources (%s): %s', $post->getId(), count($cluster['items']), $cluster['topic'], $post->getTitle()));
            } catch (\Throwable $e) {
                if (!$this->em->isOpen()) {
                    throw $e;
                }
                $failed = true;
                foreach ($cluster['items'] as $item) {
                    $item->setError('Draft generation failed; this source will be retried.');
                }
                $run->addLog(sprintf('Topic "%s" failed; its sources remain pending for retry.', $cluster['topic']), true);
                $this->logger->error('Automation could not draft a synthesis', ['topic' => $cluster['topic'], 'exception' => $e]);
            }
        }
        // The digest covered the period: articles it left out don't roll into the next one (failed topics stay pending)
        if (!$failed) {
            $skipped = 0;
            foreach ($items as $item) {
                if ($item->getStatus() === FeedItem::STATUS_NEW) {
                    $item->setStatus(FeedItem::STATUS_SKIPPED);
                    ++$skipped;
                }
            }
            $run->addLog(sprintf('%d articles were not part of any topic and were marked skipped.', $skipped));
        }
    }

    /**
     * Every article still pending: each digest marks what it left out as skipped, so these are the articles collected
     * since the last digest (by fetch, not by the feed's own date, which is often late). Never older than the
     * "ignore older than" setting.
     *
     * @return list<FeedItem>
     */
    private function periodItems(): array
    {
        return array_values($this->items->findCandidates(new \DateTimeImmutable('-' . $this->settings->maxAgeDays() . ' days'), $this->settings->minScore(), 150));
    }

    /**
     * Source action from the drafts list: draft the topic this article belongs to, with its related pending articles.
     */
    public function createPost(FeedItem $item, ?User $author = null, ?bool $publish = null): Post
    {
        $this->lock();
        try {
            $this->em->refresh($item);
            if ($item->getPost() !== null) {
                return $item->getPost();
            }
            $since = new \DateTimeImmutable('-' . $this->settings->maxAgeDays() . ' days');
            $candidates = [$item];
            foreach ($this->items->findCandidates($since, $this->settings->minScore(), 150) as $candidate) {
                if ($candidate->getFingerprint() !== $item->getFingerprint()) {
                    $candidates[] = $candidate;
                }
            }
            foreach ($this->drafter->plan($candidates, 5, 'on-demand') as $cluster) {
                if (in_array($item, $cluster['items'], true)) {
                    return $this->createSynthesis($cluster['items'], $cluster['topic'], $author, $cluster['angle']);
                }
            }
            throw new \RuntimeException('No related source is available yet. A draft requires at least two distinct articles on a shared topic.');
        } finally {
            $this->unlock();
        }
    }

    /** @param list<FeedItem> $items */
    private function createSynthesis(array $items, string $topic, ?User $author, string $angle = ''): Post
    {
        $author ??= $this->users->findOneBy(['role' => UserRole::Admin], ['id' => 'ASC'])
            ?? throw new \RuntimeException('No admin user to author automated posts.');

        $draft = $this->drafter->draft($items, $topic, $angle, $author->getDisplayName());
        $slug = $this->slugs->makeUnique(
            mb_substr($this->slugs->generate($draft->title), 0, 120),
            fn (string $candidate): bool => $this->posts->findBySlug($candidate) !== null,
        );
        $banner = $this->banners->generate($draft->title, $draft->kicker, $slug, 'ES Blog / ' . $topic);
        $categorySlug = $this->settings->categorySlug();

        return $this->em->wrapInTransaction(function () use ($draft, $slug, $banner, $categorySlug, $author, $items): Post {
            $post = $this->posts->create([
                'title' => $draft->title,
                'slug' => $slug,
                'description' => $draft->description,
                'body' => $draft->body,
                'tags' => $draft->tags,
                'image' => $banner,
                'status' => PostStatus::Draft,
                'category' => $categorySlug ? $this->categories->findBySlug($categorySlug) : null,
            ], $author);
            $post->setSourceUrl($items[0]->getLink());
            $post->setCustomField('linkedin_text', $draft->linkedin);
            $post->setCustomField('twitter_text', $draft->twitter);
            $post->setCustomField('editorial_sources', json_encode(array_map(static fn (FeedItem $item): array => [
                'title' => $item->getTitle(), 'publisher' => $item->getSource()->getName(), 'url' => $item->getLink(),
            ], $items), JSON_THROW_ON_ERROR));
            foreach ($items as $item) {
                $item->setStatus(FeedItem::STATUS_DRAFTED)->setPost($post)->setError(null);
            }

            return $post;
        });
    }

    private function lock(): void
    {
        if ((int) $this->em->getConnection()->fetchOne("SELECT GET_LOCK(CONCAT(DATABASE(), ':editorial'), 0)") !== 1) {
            throw new \RuntimeException('Another editorial run is active. Try again after it finishes.');
        }
    }

    private function unlock(): void
    {
        $this->em->getConnection()->fetchOne("SELECT RELEASE_LOCK(CONCAT(DATABASE(), ':editorial'))");
    }
}
