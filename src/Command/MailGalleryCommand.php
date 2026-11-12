<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\NewsletterSubscriber;
use App\MessageHandler\SendWelcomeEmailHandler;
use App\Service\Newsletter\NewsletterMailer;
use App\Service\Newsletter\NewsletterSubscriptions;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Twig\Environment;

/**
 * Sends every email the blog sends, with sample data, so they can be reviewed side by side
 * (`console mail:gallery es-blog` in the workspace runs it and points it at the local Mailpit).
 * Nothing is stored: the subscriber is an unsaved sample, the tokens are random.
 */
#[AsCommand(name: 'app:mail:gallery', description: 'Send every blog email with sample data, for review in Mailpit')]
final class MailGalleryCommand extends Command
{
    public function __construct(
        private readonly NewsletterMailer $mailer,
        private readonly NewsletterSubscriptions $subscriptions,
        private readonly MailerInterface $appMailer,
        private readonly Environment $twig,
        #[Autowire('%env(default::MAILER_FROM)%')] private readonly ?string $mailerFrom = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('to', null, InputOption::VALUE_REQUIRED, 'Recipient', 'gallery+es-blog@datasparklab.local');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->mailer->ready()) {
            $output->writeln('<error>Newsletter mail is not ready: it needs a real SMTP transport, a sender and an HTTPS site.url (Admin → Settings).</error>');

            return Command::FAILURE;
        }
        $subscriber = new NewsletterSubscriber((string) $input->getOption('to'));
        $subscriber->posts = true;
        $subscriber->products = true;

        $emails = [
            'Subscription confirmation' => ['Confirm your ES Blog subscription',
                "You requested new blog posts and product/release updates.\nIf this was not you, ignore this email. No subscription is active until you confirm.",
                bin2hex(random_bytes(32))],
            'New post' => ['Shipping a Symfony app to a small VPS without the drama',
                "How I deploy every app with one workflow, a reverse proxy and no downtime.\n\nRead the post: "
                . $this->mailer->url('app_blog_index'), null],
            'Reader welcome' => [NewsletterSubscriptions::WELCOME_SUBJECT, $this->subscriptions->welcomeBody($subscriber), null],
            'Product update' => ['ES Store: four new free themes',
                "Four new free themes are live in the ES Store, each with light and dark modes.\n\nThanks for following along.", null],
        ];
        foreach ($emails as $label => [$subject, $body, $confirmation]) {
            $this->mailer->send($subscriber, $subject, $body, $confirmation);
            $output->writeln(sprintf('  %-26s %s', $label, $subject));
        }
        $to = (string) $input->getOption('to');
        $this->appMailer->send(SendWelcomeEmailHandler::build(
            (new Email())->from((string) $this->mailerFrom)->to($to)->subject('Welcome to ES Blog'),
            $this->twig, 'Sample Author', $to, $this->mailer->url('app_login'),
        ));
        $output->writeln(sprintf('  %-26s %s', 'Author welcome', 'Welcome to ES Blog (new author)'));
        $output->writeln(sprintf('  %d sent from %s', \count($emails) + 1, $this->mailer->from()));

        return Command::SUCCESS;
    }
}
