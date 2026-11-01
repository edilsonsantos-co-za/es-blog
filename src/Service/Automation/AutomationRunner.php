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
 * Reads feeds and writes review-only topic syntheses from multiple articles.
 */
final class AutomationRunner
{
    public function __construct(
        private readonly AutomationSettings $settings,
        private readonly FeedSourceRepository $sources,
        private readonly FeedItemRepository $items,
        private readonly FeedFetcher $fetcher,
        private readonly PostDrafter $drafter,
        private readonly TopicGrouper $grouper,
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
            $since = new \DateTimeImmutable('-' . $this->settings->maxAgeDays() . ' days');
            $groups = $this->grouper->group(
                $this->items->findCandidates($since, $this->settings->minScore(), 100),
                $this->settings->topics(),
                $limit,
            );
            if ($groups === []) {
                $run->addLog('Waiting for at least two distinct articles on a shared configured topic. No single-source posts were created.');
            }
            foreach ($groups as $topic => $items) {
                try {
                    $post = $this->createSynthesis($items, $topic, $author);
                    $run->addPostCreated();
                    $run->addLog(sprintf('Draft #%d from %d %s sources: %s', $post->getId(), count($items), $topic, $post->getTitle()));
                } catch (\Throwable $e) {
                    if (!$this->em->isOpen()) {
                        throw $e;
                    }
                    foreach ($items as $item) {
                        $item->setError('Draft generation failed; this source will be retried.');
                    }
                    $run->addLog(sprintf('Topic "%s" failed; sources remain pending for retry.', $topic), true);
                    $this->logger->error('Automation could not draft a synthesis', ['topic' => $topic, 'exception' => $e]);
                }
            }
        }

        $run->finish();
        $this->settings->markRun($run->getStartedAt());
        $this->em->flush();

        return $run;
    }

    /**
     * Legacy source action: include related sources, never draft or publish an individual article.
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
            $groups = $this->grouper->group(
                array_merge([$item], $this->items->findCandidates($since, $this->settings->minScore(), 100)),
                $this->settings->topics(),
                5,
            );
            foreach ($groups as $topic => $items) {
                if (in_array($item, $items, true)) {
                    return $this->createSynthesis($items, $topic, $author);
                }
            }
            throw new \RuntimeException('No related source is available yet. A draft requires at least two distinct articles on a shared topic.');
        } finally {
            $this->unlock();
        }
    }

    /** @param list<FeedItem> $items */
    private function createSynthesis(array $items, string $topic, ?User $author): Post
    {
        $author ??= $this->users->findOneBy(['role' => UserRole::Admin], ['id' => 'ASC'])
            ?? throw new \RuntimeException('No admin user to author automated posts.');

        $draft = $this->drafter->draft($items, $topic);
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
