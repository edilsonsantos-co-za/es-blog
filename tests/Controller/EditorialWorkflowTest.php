<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Post;
use App\Entity\User;
use App\Entity\FeedItem;
use App\Entity\FeedSource;
use App\Entity\NewsletterCampaign;
use App\Entity\XPostShare;
use App\Enum\PostStatus;
use App\Enum\UserRole;
use App\Kernel;
use App\Service\PostService;
use App\Service\SiteConfigService;
use App\Service\Automation\AutomationRunner;
use App\Service\Automation\AutomationSettings;
use App\Service\Automation\PostDrafter;
use App\Message\PublishScheduledPostsMessage;
use App\MessageHandler\PublishScheduledPostsHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class EditorialWorkflowTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    private function browser(): KernelBrowser
    {
        if (getenv('EDITORIAL_INTEGRATION_DATABASE') !== '1') {
            self::markTestSkipped('Run against a migrated es_blog_validation_* database with EDITORIAL_INTEGRATION_DATABASE=1.');
        }
        $client = self::createClient();
        $db = self::getContainer()->get(EntityManagerInterface::class)->getConnection()->getDatabase();
        if (!str_starts_with((string) $db, 'es_blog_validation_')) {
            self::markTestSkipped('Refusing to write fixtures outside the isolated validation database.');
        }

        return $client;
    }

    private function author(UserRole $role = UserRole::Author): User
    {
        $name = 'preview_' . bin2hex(random_bytes(5));
        $user = (new User())->setUsername($name)->setEmail($name . '@example.test')
            ->setPassword('test-only-unused-password')->setRole($role);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function draft(User $author): Post
    {
        return self::getContainer()->get(PostService::class)->create([
            'title' => 'Original draft ' . bin2hex(random_bytes(5)),
            'body' => '## Original insight' . "\n\n" . 'A reviewed explanation for readers.',
            'status' => PostStatus::Draft,
            'image' => '/assets/brand/es-mark.svg',
        ], $author);
    }

    public function testOwnerPreviewRendersReaderExperienceWithoutPublishingOrViews(): void
    {
        $client = $this->browser();
        $author = $this->author();
        $post = $this->draft($author);
        $id = $post->getId();
        $client->loginUser($author);
        $client->request('GET', '/admin/post/' . $id . '/preview');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.post-title', $post->getTitle());
        self::assertSelectorExists('.post-cover img');
        self::assertSelectorTextContains('.post-body', 'Original insight');
        // Full post and platform previews are tabs: the full post shows first, the others wait behind their tab
        self::assertSelectorCount(5, '.share-tabs [role="tab"]');
        self::assertSelectorExists('#share-tab-post[aria-selected="true"]');
        self::assertSelectorExists('#share-panel-post .single-post');
        self::assertSelectorExists('#share-panel-blog[hidden]');
        self::assertSelectorExists('#share-panel-linkedin[hidden]');
        self::assertSelectorExists('#share-panel-x[hidden]');
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow, noarchive');
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        $saved = self::getContainer()->get(EntityManagerInterface::class)->find(Post::class, $id);
        self::assertSame(0, $saved->getViews());
        self::assertSame(PostStatus::Draft, $saved->getStatus());
        $client->request('GET', '/' . $post->getCreatedAt()->format('Y/m') . '/' . $post->getSlug());
        self::assertResponseStatusCodeSame(404);
    }

    public function testAnotherAuthorCannotPreviewOrListPrivateDraft(): void
    {
        $client = $this->browser();
        $post = $this->draft($this->author());
        $other = $this->author();
        $client->loginUser($other);
        $client->request('GET', '/admin/post/' . $post->getId() . '/preview');
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', '/admin/posts/drafts');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString($post->getTitle(), (string) $client->getResponse()->getContent());
    }

    public function testEveryDraftHasSocialCounterpartsAndBothPlatformTabsRender(): void
    {
        $client = $this->browser();
        $author = $this->author();
        $post = $this->draft($author);
        self::assertNotEmpty($post->getCustomFieldValue('linkedin_text'));
        self::assertNotEmpty($post->getCustomFieldValue('twitter_text'));
        $client->loginUser($author);
        foreach (['linkedin', 'twitter'] as $platform) {
            $client->request('GET', '/admin/posts/drafts?platform=' . $platform);
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('textarea[name="text"]');
            self::assertStringContainsString($post->getTitle(), (string) $client->getResponse()->getContent());
        }
    }

    public function testAutomationPersistsOneReviewedMultiSourceDraftAndConsumesSourcesOnce(): void
    {
        $client = $this->browser();
        $client->disableReboot();
        $author = $this->author();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(5));
        $feed = (new FeedSource('Validation engineering', 'https://example.test/feed-' . $suffix))->setEnabled(false);
        $first = (new FeedItem($feed, 'https://example.test/first-' . $suffix, 'PHP compatibility changes'))->setScore(1);
        $second = (new FeedItem($feed, 'https://example.test/second-' . $suffix, 'PHP deployment changes'))->setScore(1);
        foreach ([$feed, $first, $second] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $calls = 0;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$calls): MockResponse {
            ++$calls;
            // First call plans the topics, the second writes the draft
            if (str_contains(json_decode($options['body'], true)['system'], 'Group them by shared topic')) {
                return new MockResponse(json_encode([
                    'stop_reason' => 'end_turn',
                    'content' => [['type' => 'text', 'text' => json_encode(['clusters' => [
                        ['topic' => 'PHP deployments', 'angle' => 'What to check before changing PHP runtimes.', 'item_ids' => [1, 2]],
                    ]], JSON_THROW_ON_ERROR)]],
                ], JSON_THROW_ON_ERROR));
            }
            return new MockResponse(json_encode([
                'stop_reason' => 'end_turn',
                'content' => [['type' => 'text', 'text' => json_encode([
                    'title' => 'Compatibility checks before a PHP deployment',
                    'description' => 'Compare compatibility and operational perspectives before changing PHP runtimes.',
                    'kicker' => 'PHP operations',
                    'body' => 'The two reports place different parts of a deployment in focus. Compatibility verification and operational planning should be considered together rather than treated as interchangeable evidence.',
                    'tags' => 'php, deployment',
                    'linkedin' => 'Compare compatibility and operational evidence before a PHP deployment.',
                    'twitter' => 'Compatibility checks and operational planning matter together before a PHP deployment.',
                ], JSON_THROW_ON_ERROR)]],
            ], JSON_THROW_ON_ERROR));
        });
        self::getContainer()->set(PostDrafter::class, new PostDrafter(
            self::getContainer()->get(AutomationSettings::class), 'test-only-key', 'claude-opus-5-5', $http,
        ));
        $runner = self::getContainer()->get(AutomationRunner::class);
        $post = $runner->createPost($first, $author, true);
        try {
            self::assertSame(PostStatus::Draft, $post->getStatus(), 'Legacy publish mode cannot bypass review.');
            self::assertCount(2, json_decode($post->getCustomFieldValue('editorial_sources'), true));
            self::assertSame('Compare compatibility and operational evidence before a PHP deployment.', $post->getCustomFieldValue('linkedin_text'));
            self::assertNotEmpty($post->getCustomFieldValue('twitter_text'));
            self::assertNotNull($post->getImage());
            self::assertSame(FeedItem::STATUS_DRAFTED, $second->getStatus());
            self::assertSame($post->getId(), $second->getPost()->getId());
            self::assertSame($post->getId(), $runner->createPost($first, $author)->getId());
            self::assertSame(2, $calls, 'Consumed sources must not call AI or create another post.');
            $client->loginUser($author);
            $client->request('GET', '/admin/post/' . $post->getId() . '/preview');
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('.post-cover img[src="' . $post->getImage() . '"]');
        } finally {
            $banner = dirname(__DIR__, 2) . '/public' . $post->getImage();
            if (str_starts_with((string) $post->getImage(), '/uploads/banners/') && is_file($banner)) {
                unlink($banner);
            }
            $em->remove($feed);
            $em->remove($post);
            $em->flush();
        }
    }

    public function testPublicAccountLinksRequireHttpUrlsBeforeSaving(): void
    {
        $client = $this->browser();
        $client->disableReboot();
        $client->loginUser($this->author(UserRole::Admin));
        $config = self::getContainer()->get(SiteConfigService::class);
        $config->set('site.upwork_url', '');
        $url = self::getContainer()->get(UrlGeneratorInterface::class)->generate('app_admin_config');
        $crawler = $client->request('GET', $url);
        self::assertResponseIsSuccessful();
        $client->submit($crawler->selectButton('Save General Settings')->form([
            'site_upwork_url' => 'javascript:alert(1)',
        ]));
        self::assertResponseRedirects($url);
        self::assertSame('', $config->get('site.upwork_url'));
        $crawler = $client->followRedirect();
        self::assertStringContainsString('Public links must be valid HTTP(S) URLs.', $client->getResponse()->getContent());
        $client->submit($crawler->selectButton('Save General Settings')->form([
            'site_upwork_url' => 'https://example.test/public-profile',
        ]));
        self::assertResponseRedirects($url);
        self::assertSame('https://example.test/public-profile', $config->get('site.upwork_url'));
    }

    public function testXAttemptHistoryShowsPendingStateWithoutLeakingAnotherAuthorsPost(): void
    {
        $client = $this->browser();
        $author = $this->author();
        $own = $this->draft($author);
        $other = $this->draft($this->author());
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist((new XPostShare())->setPost($own));
        $em->persist((new XPostShare())->setPost($other));
        $em->flush();
        $client->loginUser($author);
        $client->request('GET', '/admin/x');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', $own->getTitle());
        self::assertSelectorTextContains('table', 'pending');
        self::assertStringNotContainsString($other->getTitle(), $client->getResponse()->getContent());
    }

    public function testFirstAndScheduledPublicationQueueOnceAndNeverBeforeDue(): void
    {
        $this->browser();
        $container = self::getContainer();
        $config = $container->get(SiteConfigService::class);
        $config->set('newsletter.enabled', '1');
        try {
            $posts = $container->get(PostService::class);
            $em = $container->get(EntityManagerInterface::class);
            $post = $this->draft($this->author());
            $posts->update($post, ['status' => PostStatus::Published]);
            self::assertSame(1, $em->getRepository(NewsletterCampaign::class)->count(['post' => $post]));
            $posts->update($post, ['body' => 'A reviewed clarification after publication.']);
            self::assertSame(1, $em->getRepository(NewsletterCampaign::class)->count(['post' => $post]));
            $scheduled = $this->draft($this->author());
            $posts->update($scheduled, ['status' => PostStatus::Scheduled, 'published_at' => new \DateTime('-1 minute')]);
            $future = $this->draft($this->author());
            $posts->update($future, ['status' => PostStatus::Scheduled, 'published_at' => new \DateTime('+1 day')]);
            $container->get(PublishScheduledPostsHandler::class)(new PublishScheduledPostsMessage());
            self::assertSame(1, $em->getRepository(NewsletterCampaign::class)->count(['post' => $scheduled]));
            self::assertSame(0, $em->getRepository(NewsletterCampaign::class)->count(['post' => $future]));
            self::assertSame(PostStatus::Scheduled, $future->getStatus());
        } finally {
            $config->set('newsletter.enabled', '0');
        }
    }
}
