<?php

declare(strict_types=1);

namespace App\Tests\Service\Newsletter;

use App\Controller\Blog\NewsletterController;
use App\Entity\NewsletterCampaign;
use App\Entity\NewsletterDelivery;
use App\Entity\NewsletterSubscriber;
use App\Entity\Post;
use App\Message\DeliverNewsletterMessage;
use App\MessageHandler\DeliverNewsletterHandler;
use App\Repository\SiteConfigRepository;
use App\Service\Newsletter\NewsletterMailer;
use App\Service\Newsletter\NewsletterQueue;
use App\Service\Newsletter\NewsletterSubscriptions;
use App\Service\SiteConfigService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class NewsletterTest extends TestCase
{
    public function testConfirmationIsExpiringSingleUseAndConsentIsSeparate(): void
    {
        $subscriber = new NewsletterSubscriber('reader@example.com');
        $token = bin2hex(random_bytes(32));
        $subscriber->confirmationHash = hash('sha256', $token);
        $subscriber->confirmationExpiresAt = new \DateTimeImmutable('+1 hour');
        $subscriber->posts = true;
        self::assertFalse($subscriber->eligible('post'));
        self::assertFalse($subscriber->confirm(str_repeat('0', 64)));
        self::assertFalse($subscriber->confirm($token, new \DateTimeImmutable('+2 hours')));
        self::assertTrue($subscriber->confirm($token));
        self::assertTrue($subscriber->eligible('post'));
        self::assertFalse($subscriber->eligible('product'));
        self::assertFalse($subscriber->eligible('unexpected'));
        self::assertFalse($subscriber->confirm($token));
        $subscriber->unsubscribe();
        self::assertFalse($subscriber->eligible('post'));
        self::assertFalse($subscriber->eligible('product'));
    }

    public function testSubscribeStoresOnlyTokenHashesAndDoesNotActivateConsent(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findOneBy')->willReturn(null);
        $em->method('getRepository')->willReturn($repository);
        $stored = null;
        $em->expects(self::once())->method('persist')->willReturnCallback(function ($subscriber) use (&$stored): void {
            $stored = $subscriber;
        });
        $em->expects(self::once())->method('flush');
        $transport->expects(self::once())->method('send')->willReturnCallback(function (Email $email) use (&$stored): null {
            self::assertSame('reader@example.com', $email->getTo()[0]->getAddress());
            self::assertStringContainsString('product/release updates', $email->getTextBody());
            self::assertStringNotContainsString('You requested new blog posts', $email->getTextBody());
            preg_match('~/newsletter/confirm/([a-f0-9]{64})~', $email->getTextBody(), $matches);
            self::assertSame(hash('sha256', $matches[1]), $stored->confirmationHash);
            self::assertFalse($stored->eligible('product'));
            self::assertFalse($stored->posts);
            self::assertTrue($stored->products);
            self::assertNotNull($email->getHeaders()->get('List-Unsubscribe'));
            self::assertSame('List-Unsubscribe=One-Click', $email->getHeaders()->get('List-Unsubscribe-Post')->getBodyAsString());
            self::assertStringContainsString('Unsubscribe or manage preferences', $email->getHtmlBody());
            return null;
        });
        $service = new NewsletterSubscriptions($em, $this->mailer($transport), new NullLogger());
        $service->request(' Reader@Example.com ', false, true);
    }

    public function testMissingConsentIsRejectedBeforePersistenceOrDelivery(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $transport->expects(self::never())->method('send');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');
        $service = new NewsletterSubscriptions($em, $this->mailer($transport), new NullLogger());
        $this->expectException(\InvalidArgumentException::class);
        $service->request('reader@example.com', false, false);
    }

    public function testNullTransportNeverCreatesPendingSubscriptionOrSends(): void
    {
        self::assertFalse(NewsletterMailer::deliverableDsn('null://null'));
        self::assertFalse(NewsletterMailer::deliverableDsn('failover(smtp://mail.example.com null://null)'));
        self::assertFalse(NewsletterMailer::deliverableDsn('sendmail://default'));
        $transport = $this->createMock(TransportInterface::class);
        $transport->expects(self::never())->method('send');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('getRepository');
        $service = new NewsletterSubscriptions($em, $this->mailer($transport, 'null://null'), new NullLogger());
        $service->request('reader@example.com', true, false);
    }

    public function testPublicRequestsCannotChangeExistingConfirmedConsent(): void
    {
        $subscriber = new NewsletterSubscriber('reader@example.com');
        $subscriber->confirmedAt = new \DateTimeImmutable('-1 day');
        $subscriber->posts = true;
        $subscriber->requestedAt = new \DateTimeImmutable('-2 days');
        $em = $this->createMock(EntityManagerInterface::class);
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findOneBy')->willReturn($subscriber);
        $em->method('getRepository')->willReturn($repository);
        $em->expects(self::never())->method('persist');
        $transport = $this->createMock(TransportInterface::class);
        $transport->expects(self::never())->method('send');
        (new NewsletterSubscriptions($em, $this->mailer($transport), new NullLogger()))
            ->request($subscriber->email, false, true);
        self::assertTrue($subscriber->posts);
        self::assertFalse($subscriber->products);
    }

    public function testUnsubscribeSuppressesAlreadyQueuedDelivery(): void
    {
        $delivery = $this->productDelivery();
        $delivery->subscriber->unsubscribe();
        $transport = $this->createMock(TransportInterface::class);
        $transport->expects(self::never())->method('send');
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('update')->with('newsletter_deliveries', ['status' => 'skipped'], ['id' => 1, 'status' => 'pending'])->willReturn(1);
        $em = $this->deliveryEm($delivery, $connection);
        (new DeliverNewsletterHandler($em, $this->mailer($transport), $this->queue($em)))(new DeliverNewsletterMessage(1));
    }

    public function testFutureDatedPublishedPostCannotBeDeliveredEarly(): void
    {
        $delivery = $this->productDelivery();
        $delivery->campaign->kind = 'post';
        $delivery->subscriber->posts = true;
        $delivery->campaign->post = (new Post())->setPublishedAt(new \DateTimeImmutable('+1 day'));
        $transport = $this->createMock(TransportInterface::class);
        $transport->expects(self::never())->method('send');
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('executeStatement');
        $connection->expects(self::never())->method('update');
        $em = $this->deliveryEm($delivery, $connection);
        (new DeliverNewsletterHandler($em, $this->mailer($transport), $this->queue($em)))(new DeliverNewsletterMessage(1));
    }

    public function testConsentIsRecheckedAfterClaimAndBeforeProviderCall(): void
    {
        $delivery = $this->productDelivery();
        $transport = $this->createMock(TransportInterface::class);
        $transport->expects(self::never())->method('send');
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturn(1);
        $connection->expects(self::once())->method('update')->with('newsletter_deliveries', ['status' => 'skipped'], ['id' => 1])->willReturn(1);
        $em = $this->deliveryEm($delivery, $connection);
        $refreshes = 0;
        $em->method('refresh')->willReturnCallback(function ($entity) use ($delivery, &$refreshes): void {
            if ($entity === $delivery->subscriber && ++$refreshes === 2) {
                $entity->unsubscribe();
            }
        });
        (new DeliverNewsletterHandler($em, $this->mailer($transport), $this->queue($em)))(new DeliverNewsletterMessage(1));
    }

    public function testAcceptedDeliveryIsNotResentOnDuplicateJob(): void
    {
        $delivery = $this->productDelivery();
        $transport = $this->createMock(TransportInterface::class);
        $transport->expects(self::once())->method('send')->willReturn(null);
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('executeStatement')->willReturn(1);
        $connection->expects(self::once())->method('update')->willReturnCallback(function ($table, $data) use ($delivery): int {
            self::assertSame('sent', $data['status']);
            $delivery->status = 'sent';
            return 1;
        });
        $em = $this->deliveryEm($delivery, $connection);
        $handler = new DeliverNewsletterHandler($em, $this->mailer($transport), $this->queue($em));
        $handler(new DeliverNewsletterMessage(1));
        $handler(new DeliverNewsletterMessage(1));
    }

    public function testAmbiguousProviderFailureIsNotAutomaticallyResent(): void
    {
        $delivery = $this->productDelivery();
        $transport = $this->createMock(TransportInterface::class);
        $transport->expects(self::once())->method('send')->willThrowException(new \RuntimeException('Lost acknowledgement'));
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('executeStatement')->willReturn(1);
        $connection->expects(self::once())->method('update')->willReturnCallback(function ($table, $data) use ($delivery): int {
            self::assertSame('uncertain', $data['status']);
            $delivery->status = 'uncertain';
            return 1;
        });
        $em = $this->deliveryEm($delivery, $connection);
        $handler = new DeliverNewsletterHandler($em, $this->mailer($transport), $this->queue($em));
        try {
            $handler(new DeliverNewsletterMessage(1));
            self::fail('Transport failure should reach the failed-message workflow.');
        } catch (\RuntimeException $e) {
            self::assertSame('Lost acknowledgement', $e->getMessage());
        }
        $handler(new DeliverNewsletterMessage(1));
    }

    public function testOneClickUnsubscribeRequiresNoSessionAndIsIdempotent(): void
    {
        $subscriber = $this->productDelivery()->subscriber;
        $transport = $this->createMock(TransportInterface::class);
        $transport->expects(self::never())->method('send');
        $mailer = $this->mailer($transport);
        $token = $mailer->unsubscribeToken($subscriber);
        $subscriber->unsubscribeHash = hash('sha256', $token);
        $em = $this->createMock(EntityManagerInterface::class);
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findOneBy')->willReturnCallback(function ($criteria) use ($token, $subscriber) {
            self::assertSame(['unsubscribeHash' => hash('sha256', $token)], $criteria);
            return $subscriber;
        });
        $em->method('getRepository')->willReturn($repository);
        $em->expects(self::exactly(2))->method('flush');
        $controller = new NewsletterController(new NewsletterSubscriptions($em, $mailer, new NullLogger()), $mailer, $em,
            new RateLimiterFactory(['id' => 'newsletter', 'policy' => 'sliding_window', 'limit' => 5, 'interval' => '1 hour'], new InMemoryStorage()));
        $request = Request::create('/newsletter/unsubscribe/' . $token, 'POST', ['List-Unsubscribe' => 'One-Click']);
        self::assertSame(200, $controller->unsubscribe($token, $request)->getStatusCode());
        self::assertSame(200, $controller->unsubscribe($token, $request)->getStatusCode());
        self::assertFalse($request->hasSession());
        self::assertFalse($subscriber->eligible('product'));
    }

    public function testPublicSubscribeRejectsInvalidCsrfBeforePersistence(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');
        $transport = $this->createMock(TransportInterface::class);
        $transport->expects(self::never())->method('send');
        $mailer = $this->mailer($transport);
        $controller = new NewsletterController(new NewsletterSubscriptions($em, $mailer, new NullLogger()), $mailer, $em,
            new RateLimiterFactory(['id' => 'newsletter', 'policy' => 'sliding_window', 'limit' => 5, 'interval' => '1 hour'], new InMemoryStorage()));
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(false);
        $container = new Container();
        $container->set('security.csrf.token_manager', $csrf);
        $controller->setContainer($container);
        $this->expectException(AccessDeniedException::class);
        $controller->subscribe(Request::create('/newsletter', 'POST', ['email' => 'reader@example.com', 'posts' => '1', '_token' => 'invalid']));
    }

    private function config(): SiteConfigService
    {
        $repo = $this->createStub(SiteConfigRepository::class);
        $repo->method('get')->willReturnCallback(fn ($key, $default = null) => [
            'newsletter.from' => 'newsletter@example.com', 'site.url' => 'https://blog.example.com', 'newsletter.enabled' => '1',
        ][$key] ?? $default);
        return new SiteConfigService($repo, new ArrayAdapter());
    }

    private function mailer(TransportInterface $transport, string $dsn = 'smtp://mailpit:1025'): NewsletterMailer
    {
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(fn ($route, $params = []) => '/newsletter/'
            . ($route === 'app_newsletter_confirm' ? 'confirm' : 'unsubscribe') . '/' . ($params['token'] ?? ''));
        return new NewsletterMailer($transport, $this->config(), $urls,
            new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/templates')), $dsn, 'test-secret');
    }

    private function queue(EntityManagerInterface $em): NewsletterQueue
    {
        return new NewsletterQueue($em, $this->createStub(MessageBusInterface::class), $this->config());
    }

    private function productDelivery(): NewsletterDelivery
    {
        $delivery = new NewsletterDelivery();
        $delivery->id = 1;
        $delivery->campaign = new NewsletterCampaign();
        $delivery->campaign->kind = 'product';
        $delivery->campaign->subject = 'A reviewed release';
        $delivery->campaign->body = 'Release details';
        $delivery->subscriber = new NewsletterSubscriber('reader@example.com');
        $delivery->subscriber->products = true;
        $delivery->subscriber->confirmedAt = new \DateTimeImmutable('-1 day');
        return $delivery;
    }

    private function deliveryEm(NewsletterDelivery $delivery, Connection $connection): EntityManagerInterface
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturn($delivery);
        $em->method('getConnection')->willReturn($connection);
        return $em;
    }
}
