<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\FeedItem;
use App\Entity\FeedSource;
use App\Entity\User;
use App\Repository\AutomationRunRepository;
use App\Repository\CategoryRepository;
use App\Repository\FeedItemRepository;
use App\Repository\FeedSourceRepository;
use App\Service\Automation\AutomationRunner;
use App\Service\Automation\AutomationSettings;
use App\Service\Automation\BannerGenerator;
use App\Service\Automation\FeedParser;
use App\Service\Automation\PostDrafter;
use App\Service\LinkedIn\LinkedInPublisher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Feed automation: the Feeder.co feeds the blog reads, the items found, and the runs that turn them into posts.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/automation')]
class AutomationController extends AbstractController
{
    public function __construct(
        private readonly AutomationSettings $settings,
        private readonly FeedSourceRepository $sources,
        private readonly FeedItemRepository $items,
        private readonly AutomationRunRepository $runs,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('', name: 'app_admin_automation', methods: ['GET'])]
    public function index(PostDrafter $drafter, LinkedInPublisher $linkedIn): Response
    {
        return $this->renderTab('overview', [
            'runs' => $this->runs->findLatest(8),
            'ai_enabled' => $drafter->isAiEnabled(),
            'ai_model' => $drafter->model(),
            'linkedin' => $linkedIn->connection(),
        ]);
    }

    #[Route('/items', name: 'app_admin_automation_items', methods: ['GET'])]
    public function itemList(Request $request): Response
    {
        return $this->redirectToRoute('app_admin_posts_drafts');
    }

    #[Route('/feeds', name: 'app_admin_automation_feeds', methods: ['GET'])]
    public function feeds(): Response
    {
        return $this->renderTab('feeds');
    }

    #[Route('/settings', name: 'app_admin_automation_settings', methods: ['GET'])]
    public function settingsForm(CategoryRepository $categories): Response
    {
        return $this->renderTab('settings', ['categories' => $categories->findAllOrdered()]);
    }

    #[Route('/settings', name: 'app_admin_automation_settings_save', methods: ['POST'])]
    public function settings(Request $request): Response
    {
        $this->guard($request, 'automation_settings');
        $r = $request->request;
        $this->settings->save([
            'automation.enabled' => $r->getBoolean('enabled') ? '1' : '0',
            'automation.interval_hours' => (string) max(1, min(168, $r->getInt('interval_hours', 24))),
            'automation.posts_per_run' => (string) max(0, min(5, $r->getInt('posts_per_run', 1))),
            'automation.max_age_days' => (string) max(1, min(30, $r->getInt('max_age_days', 7))),
            'automation.min_score' => (string) max(0, min(20, $r->getInt('min_score', 1))),
            'automation.publish_mode' => AutomationSettings::MODE_DRAFT,
            'automation.category' => trim((string) $r->get('category', '')) ?: null,
            'automation.topics' => trim((string) $r->get('topics', '')) ?: AutomationSettings::DEFAULT_TOPICS,
            'automation.voice' => trim((string) $r->get('voice', '')) ?: null,
        ]);
        $this->addFlash('success', 'Automation settings saved.');

        return $this->redirectToRoute('app_admin_automation_settings');
    }

    #[Route('/run', name: 'app_admin_automation_run', methods: ['POST'])]
    public function run(Request $request, AutomationRunner $runner): Response
    {
        $this->guard($request, 'automation_run');
        set_time_limit(600);
        $posts = $request->request->get('posts');
        $user = $this->getUser();
        $run = $runner->run('admin', $posts === null || $posts === '' ? null : (int) $posts, $user instanceof User ? $user : null);

        $this->addFlash($run->isSuccess() ? 'success' : 'warning', sprintf(
            'Run finished: %d feeds read, %d new items, %d posts created.%s',
            $run->getFeedsRead(),
            $run->getItemsAdded(),
            $run->getPostsCreated(),
            $run->isSuccess() ? '' : ' Some steps failed; see the run log.'
        ));

        return $this->redirectToRoute($run->getPostsCreated() > 0 ? 'app_admin_posts_drafts' : 'app_admin_automation');
    }

    #[Route('/feeds', name: 'app_admin_automation_feed_add', methods: ['POST'])]
    public function addFeed(Request $request): Response
    {
        $this->guard($request, 'automation_feed_add');
        $url = trim((string) $request->request->get('url'));
        if (!preg_match('#^https?://#i', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            $this->addFlash('error', 'Enter a full feed URL starting with http:// or https://.');

            return $this->redirectToRoute('app_admin_automation_feeds');
        }
        if ($this->sources->findOneBy(['url' => $url])) {
            $this->addFlash('info', 'That feed is already in the list.');

            return $this->redirectToRoute('app_admin_automation_feeds');
        }
        $name = trim((string) $request->request->get('name')) ?: (parse_url($url, PHP_URL_HOST) ?: $url);
        $folder = trim((string) $request->request->get('folder')) ?: null;
        $this->em->persist(new FeedSource(mb_substr($name, 0, 255), $url, $folder));
        $this->em->flush();
        $this->addFlash('success', sprintf('Added "%s".', $name));

        return $this->redirectToRoute('app_admin_automation_feeds');
    }

    #[Route('/feeds/import', name: 'app_admin_automation_import', methods: ['POST'])]
    public function import(Request $request, FeedParser $parser): Response
    {
        $this->guard($request, 'automation_import');
        $file = $request->files->get('opml');
        if ($file === null || !$file->isValid() || $file->getSize() > 2_000_000) {
            $this->addFlash('error', 'Choose the OPML file exported from Feeder.co (under 2 MB).');

            return $this->redirectToRoute('app_admin_automation_feeds');
        }

        try {
            $feeds = $parser->parseOpml((string) file_get_contents($file->getPathname()));
        } catch (\InvalidArgumentException) {
            $this->addFlash('error', 'That file is not valid OPML.');

            return $this->redirectToRoute('app_admin_automation_feeds');
        }

        $added = 0;
        $known = array_map(static fn (FeedSource $s): string => $s->getUrl(), $this->sources->findAll());
        foreach ($feeds as $feed) {
            if (in_array($feed['url'], $known, true) || mb_strlen($feed['url']) > 768) {
                continue;
            }
            $this->em->persist(new FeedSource(mb_substr($feed['name'], 0, 255), $feed['url'], $feed['folder'] ? mb_substr($feed['folder'], 0, 120) : null));
            $known[] = $feed['url'];
            ++$added;
        }
        $this->em->flush();
        $this->addFlash('success', sprintf('Imported %d new feeds (%d in the file).', $added, count($feeds)));

        return $this->redirectToRoute('app_admin_automation_feeds');
    }

    #[Route('/feeds/{id}/toggle', name: 'app_admin_automation_feed_toggle', methods: ['POST'])]
    public function toggleFeed(Request $request, FeedSource $source): Response
    {
        $this->guard($request, 'feed_' . $source->getId());
        $source->setEnabled(!$source->isEnabled());
        $this->em->flush();

        return $this->redirectToRoute('app_admin_automation_feeds');
    }

    #[Route('/feeds/{id}/delete', name: 'app_admin_automation_feed_delete', methods: ['POST'])]
    public function deleteFeed(Request $request, FeedSource $source): Response
    {
        $this->guard($request, 'feed_' . $source->getId());
        $this->em->remove($source);
        $this->em->flush();
        $this->addFlash('success', sprintf('Removed "%s" and its items.', $source->getName()));

        return $this->redirectToRoute('app_admin_automation_feeds');
    }

    #[Route('/items/{id}/draft', name: 'app_admin_automation_item_draft', methods: ['POST'])]
    public function draftItem(Request $request, FeedItem $item, AutomationRunner $runner): Response
    {
        $this->guard($request, 'item_' . $item->getId());
        set_time_limit(300);
        $user = $this->getUser();

        try {
            $post = $runner->createPost($item, $user instanceof User ? $user : null, false);
        } catch (\Throwable $e) {
            $item->setStatus(FeedItem::STATUS_FAILED)->setError(mb_substr($e->getMessage(), 0, 1000));
            $this->em->flush();
            $this->addFlash('error', 'Could not write the draft: ' . $e->getMessage());

            return $this->redirectToRoute('app_admin_automation_items', ['items' => FeedItem::STATUS_FAILED]);
        }
        $this->addFlash('success', 'Draft created. Review it, then publish.');

        return $this->redirectToRoute('app_admin_post_edit', ['id' => $post->getId()]);
    }

    #[Route('/items/{id}/skip', name: 'app_admin_automation_item_skip', methods: ['POST'])]
    public function skipItem(Request $request, FeedItem $item): Response
    {
        $this->guard($request, 'item_' . $item->getId());
        $item->setStatus($item->getStatus() === FeedItem::STATUS_SKIPPED ? FeedItem::STATUS_NEW : FeedItem::STATUS_SKIPPED);
        $this->em->flush();

        return $this->redirectToRoute('app_admin_automation_items', ['items' => $this->itemFilter($request)]);
    }

    #[Route('/banner-preview', name: 'app_admin_automation_banner', methods: ['GET'])]
    public function bannerPreview(Request $request, BannerGenerator $banners): Response
    {
        $title = mb_substr((string) $request->query->get('title', 'Symfony 7.4 LTS: what changes for long-running PHP apps'), 0, 200);
        $image = $banners->render($title, (string) $request->query->get('kicker', 'Symfony release'), 'Symfony Blog');
        ob_start();
        imagepng($image);

        return new Response((string) ob_get_clean(), 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'no-store']);
    }

    /**
     * Every tab shows the same tab bar, whose badges need the feed and item counts.
     *
     * @param array<string, mixed> $vars
     */
    private function renderTab(string $tab, array $vars = []): Response
    {
        return $this->render('admin/automation/index.html.twig', $vars + [
            'active_tab' => $tab,
            'settings' => $this->settings,
            'sources' => $this->sources->findAllOrdered(),
            'item_counts' => $this->items->countByStatus(),
        ]);
    }

    private function itemFilter(Request $request): string
    {
        $filter = $request->query->get('items', FeedItem::STATUS_NEW);

        return in_array($filter, [FeedItem::STATUS_NEW, FeedItem::STATUS_DRAFTED, FeedItem::STATUS_SKIPPED, FeedItem::STATUS_FAILED, 'all'], true)
            ? $filter : FeedItem::STATUS_NEW;
    }

    private function guard(Request $request, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
