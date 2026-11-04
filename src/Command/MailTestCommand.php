<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Newsletter\NewsletterMailer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;

/**
 * Sends one plain test message through the configured transport, so you can check real delivery
 * (inbox, not spam; SPF/DKIM pass) before turning the newsletter on. Prints no credentials.
 */
#[AsCommand(name: 'app:mail:test', description: 'Send a test email through MAILER_DSN and report whether it is real delivery')]
final class MailTestCommand extends Command
{
    public function __construct(
        private readonly TransportInterface $transport,
        private readonly NewsletterMailer $newsletter,
        #[Autowire('%env(MAILER_DSN)%')] private readonly string $mailerDsn,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('to', InputArgument::REQUIRED, 'Where to send the test message');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $to = (string) $input->getArgument('to');
        $host = parse_url(str_replace(['smtps://', 'smtp://'], 'http://', $this->mailerDsn), PHP_URL_HOST) ?: 'unknown';
        if (!NewsletterMailer::deliverableDsn($this->mailerDsn)) {
            $output->writeln(sprintf('<error>MAILER_DSN (%s) does not deliver real mail: it is a null transport or the Mailtrap sandbox. Set the SMTP_* GitHub secrets and redeploy.</error>', $host));

            return Command::FAILURE;
        }
        $from = $this->newsletter->from();
        if (filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
            $output->writeln('<error>No sender: set the MAIL_FROM_ADDRESS secret (MAILER_FROM) or the "from" address under Admin → Newsletter.</error>');

            return Command::FAILURE;
        }
        $this->transport->send((new Email())->from($from)->to($to)
            ->subject('ES Blog mail test')
            ->text("This is a test message from ES Blog.\n\nIf it arrived in your inbox (not spam), real delivery works and the newsletter can be enabled."));
        $output->writeln(sprintf('Sent from %s to %s through %s. Check the inbox and spam folder.', $from, $to, $host));

        return Command::SUCCESS;
    }
}
