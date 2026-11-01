<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Newsletter\NewsletterMailer;
use App\Service\Newsletter\NewsletterQueue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:newsletter:queue', description: 'Preview/recover interrupted newsletter queueing, not uncertain deliveries')]
final class NewsletterQueueCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NewsletterQueue $queue,
        private readonly NewsletterMailer $mailer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview only (default)')
            ->addOption('send', null, InputOption::VALUE_NONE, 'Queue recoverable pending work')
            ->addOption('approve', null, InputOption::VALUE_NONE, 'Approve recovery');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $connection = $this->em->getConnection();
        $io->text('Unqueued campaigns: ' . $connection->fetchOne('SELECT COUNT(*) FROM newsletter_campaigns WHERE queued_at IS NULL'));
        $io->text('Pending deliveries: ' . $connection->fetchOne("SELECT COUNT(*) FROM newsletter_deliveries WHERE status = 'pending'"));
        if (!$input->getOption('send') || $input->getOption('dry-run')) {
            $io->success('Dry run. No queue changes or email delivery.');
            return Command::SUCCESS;
        }
        if (!$input->getOption('approve') || !$this->queue->enabled() || !$this->mailer->ready()) {
            $io->error('Recovery requires --approve and enabled, configured newsletter delivery.');
            return Command::INVALID;
        }
        $io->success($this->queue->recover() . ' recovery messages queued.');

        return Command::SUCCESS;
    }
}
