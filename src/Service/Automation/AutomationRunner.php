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
use App\Service\LinkedIn\LinkedInPublisher;
use App\Service\PostService;
use App\Service\SlugService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Reads every enabled feed, then turns the best new items into posts with a generated banner.
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

        $limit = $postLimit ?? $this->settings->postsPerRun();
        if ($limit > 0) {
            $since = new \DateTimeImmutable('-' . $this->settings->maxAgeDays() . ' days');
            foreach ($this->items->findCandidates($since, $this->settings->minScore(), $limit) as $item) {
                try {
                    $post = $this->createPost($item, $author);
                    $run->addPostCreated();
                    $run->addLog(sprintf('Post #%d (%s): %s', $post->getId(), $post->getStatus()->value, $post->getTitle()));
                } catch (\Throwable $e) {
                    $item->setStatus(FeedItem::STATUS_FAILED)->setError(mb_substr($e->getMessage(), 0, 1000));
                    $run->addLog(sprintf('Item "%s" failed: %s', $item->getTitle(), $e->getMessage()), true);
                    $this->logger->error('Automation could not draft a post', ['item' => $item->getId(), 'exception' => $e]);
                }
            }
        }

        $run->finish();
        $this->settings->markRun($run->getStartedAt());
        $this->em->flush();

        return $run;
    }

    /**
     * Writes a post from one feed item. Publishes it (and so shares it on LinkedIn) only in publish mode.
     */
    public function createPost(FeedItem $item, ?User $author = null, ?bool $publish = null): Post
    {
        $publish ??= $this->settings->publishMode() === AutomationSettings::MODE_PUBLISH;
        $author ??= $this->users->findOneBy(['role' => UserRole::Admin], ['id' => 'ASC'])
            ?? throw new \RuntimeException('No admin user to author automated posts.');

        $draft = $this->drafter->draft($item, $publish);
        $slug = $this->slugs->makeUnique(
            mb_substr($this->slugs->generate($draft->title), 0, 120),
            fn (string $candidate): bool => $this->posts->findBySlug($candidate) !== null,
        );
        $banner = $this->banners->generate($draft->title, $draft->kicker, $slug, $item->getSource()->getName());
        $categorySlug = $this->settings->categorySlug();

        // Created as a draft first so the LinkedIn text is saved before any share is queued.
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
        $post->setSourceUrl($item->getLink());
        $post->addCustomField(LinkedInPublisher::commentaryField($post, $draft->linkedin));
        $item->setStatus(FeedItem::STATUS_DRAFTED)->setPost($post)->setError(null);
        $this->em->flush();

        if ($publish) {
            $this->posts->update($post, ['status' => PostStatus::Published, 'published_at' => new \DateTime()]);
        }

        return $post;
    }
}
