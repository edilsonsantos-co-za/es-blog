<?php

declare(strict_types=1);

namespace App\Tests\Service\Newsletter;

use App\Entity\NewsletterSubscriber;
use App\Service\Newsletter\NewsletterMailer;
use App\Service\SiteConfigService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

final class NewsletterHttpTest extends WebTestCase
{
    public function testAnonymousDoubleOptInAndOneClickRevocationWithRealPersistence(): void
    {
        if (getenv('NEWSLETTER_INTEGRATION') !== '1') {
            self::markTestSkipped('Enable NEWSLETTER_INTEGRATION=1 with the authorized isolated database.');
        }
        self::assertStringStartsWith('/es_blog_validation_', (string) parse_url((string) getenv('DATABASE_URL'), PHP_URL_PATH),
            'Refusing HTTP validation against any other database.');
        $client = self::createClient();
        $client->disableReboot();
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        self::assertStringStartsWith('es_blog_validation_', $em->getConnection()->getDatabase());
        $emailAddress = 'newsletter-http-validation@example.com';
        $existing = $em->getRepository(NewsletterSubscriber::class)->findOneBy(['email' => $emailAddress]);
        if ($existing !== null) {
            $em->remove($existing);
            $em->flush();
        }
        $config = $container->get(SiteConfigService::class);
        $config->setBulk(['newsletter.from' => 'newsletter@example.com', 'site.url' => 'https://blog.example.com']);
        $transport = $this->createMock(TransportInterface::class);
        $sent = null;
        $sentAll = [];
        $transport->expects(self::exactly(2))->method('send')->willReturnCallback(function (Email $email) use (&$sent, &$sentAll): null {
            $sent ??= $email;
            $sentAll[] = $email;
            return null;
        });
        $mailer = new NewsletterMailer($transport, $config, $container->get(UrlGeneratorInterface::class),
            $container->get(Environment::class), 'smtp://mailpit:1025', 'test-only-editorial-secret');
        $container->set(NewsletterMailer::class, $mailer);

        $crawler = $client->request('GET', '/newsletter');
        self::assertResponseIsSuccessful();
        $client->submit($crawler->selectButton('Subscribe')->form([
            'email' => $emailAddress, 'posts' => '1',
        ]));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Check your inbox.', $client->getResponse()->getContent());
        $em->clear();
        $subscriber = $em->getRepository(NewsletterSubscriber::class)->findOneBy(['email' => $emailAddress]);
        self::assertNotNull($subscriber);
        self::assertNull($subscriber->confirmedAt);
        self::assertTrue($subscriber->posts);
        self::assertFalse($subscriber->products);
        preg_match('~/newsletter/confirm/([a-f0-9]{64})~', $sent->getTextBody(), $matches);
        self::assertSame(hash('sha256', $matches[1]), $subscriber->confirmationHash);
        $crawler = $client->request('GET', '/newsletter/confirm/' . $matches[1]);
        self::assertResponseIsSuccessful();
        $subscriber = $em->getRepository(NewsletterSubscriber::class)->findOneBy(['email' => $emailAddress]);
        self::assertNull($subscriber->confirmedAt, 'Email scanners must not activate consent via GET.');
        $client->submit($crawler->selectButton('Confirm my subscription')->form());
        self::assertResponseIsSuccessful();
        $subscriber = $em->getRepository(NewsletterSubscriber::class)->findOneBy(['email' => $emailAddress]);
        self::assertTrue($subscriber->eligible('post'));
        self::assertFalse($subscriber->eligible('product'));
        self::assertSame('Welcome to ES Blog', $sentAll[1]->getSubject(), 'Confirming sends the reader welcome.');
        self::assertStringContainsString('new blog posts', $sentAll[1]->getTextBody());
        $token = $mailer->unsubscribeToken($subscriber);
        $client->request('GET', '/newsletter/unsubscribe/' . $token);
        self::assertResponseIsSuccessful();
        $subscriber = $em->getRepository(NewsletterSubscriber::class)->findOneBy(['email' => $emailAddress]);
        self::assertTrue($subscriber->eligible('post'), 'GET must not unsubscribe.');
        $client->request('POST', '/newsletter/unsubscribe/' . $token, ['List-Unsubscribe' => 'One-Click']);
        self::assertResponseIsSuccessful();
        $subscriber = $em->getRepository(NewsletterSubscriber::class)->findOneBy(['email' => $emailAddress]);
        self::assertFalse($subscriber->eligible('post'));
        self::assertFalse($subscriber->eligible('product'));
        self::assertNull($subscriber->confirmationHash);
        $client->request('POST', '/newsletter/unsubscribe/' . $token, ['List-Unsubscribe' => 'One-Click']);
        self::assertResponseIsSuccessful();
        $subscriber = $em->getRepository(NewsletterSubscriber::class)->findOneBy(['email' => $emailAddress]);
        $em->remove($subscriber);
        $em->flush();
    }
}
