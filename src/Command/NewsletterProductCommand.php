<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\NewsletterCampaign;
use App\Service\Newsletter\NewsletterMailer;
use App\Service\Newsletter\NewsletterQueue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:newsletter:product', description: 'Preview product/release newsletter; queue only with --send --approve')]
final class NewsletterProductCommand extends Command
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
        $this->addOption('subject', null, InputOption::VALUE_REQUIRED, 'Reviewed subject')
            ->addOption('body-file', null, InputOption::VALUE_REQUIRED, 'Reviewed UTF-8 plain-text file')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview only (the default)')
            ->addOption('send', null, InputOption::VALUE_NONE, 'Queue the reviewed campaign')
            ->addOption('approve', null, InputOption::VALUE_NONE, 'Explicit operator approval of content and audience');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $subject = trim((string) $input->getOption('subject'));
        $file = (string) $input->getOption('body-file');
        if ($subject === '' || strlen($subject) > 255 || preg_match('/[\r\n]/', $subject)
            || $file === '' || str_contains($file, '://') || !is_file($file) || !is_readable($file) || filesize($file) > 100000) {
            $io->error('Provide a subject (max 255 bytes, one line) and a readable plain-text body file (max 100 KB).');
            return Command::INVALID;
        }
        $body = trim((string) file_get_contents($file));
        if ($body === '' || preg_match('//u', $body) !== 1 || preg_match('//u', $subject) !== 1) {
            $io->error('Subject and non-empty body must be UTF-8.');
            return Command::INVALID;
        }
        $count = $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM newsletter_subscribers WHERE confirmed_at IS NOT NULL AND unsubscribed_at IS NULL AND products = 1');
        $io->title('Product/release newsletter preview');
        $io->text('Subject: ' . $subject);
        $io->text($body);
        $io->text('Eligible confirmed product subscribers: ' . $count . '. Unsubscribe links are appended automatically.');
        if (!$input->getOption('send') || $input->getOption('dry-run')) {
            $io->success('Dry run only. No campaign saved, no messages queued, no email sent.');
            return Command::SUCCESS;
        }
        if (!$input->getOption('approve') || !$this->queue->enabled() || !$this->mailer->ready()) {
            $io->error('Sending requires --approve and enabled, configured newsletter delivery.');
            return Command::INVALID;
        }
        $campaign = new NewsletterCampaign();
        $campaign->kind = 'product';
        $campaign->subject = $subject;
        $campaign->body = $body;
        $this->em->persist($campaign);
        $this->em->flush();
        $this->queue->dispatchCampaign($campaign);
        $io->success('Approved campaign ' . $campaign->id . ' queued. Worker rechecks consent before delivery.');

        return Command::SUCCESS;
    }
}
