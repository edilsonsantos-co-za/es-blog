<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Automation\AutomationRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:automation:run',
    description: 'Read every enabled feed now and turn the best new items into posts',
)]
final class AutomationRunCommand extends Command
{
    public function __construct(
        private readonly AutomationRunner $runner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('posts', null, InputOption::VALUE_REQUIRED, 'Posts to write this run (default: the admin setting; 0 = only read feeds)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $posts = $input->getOption('posts');
        $run = $this->runner->run('cli', $posts === null ? null : (int) $posts);

        foreach ($run->getLog() as $line) {
            $output->writeln('  ' . $line);
        }
        $output->writeln(sprintf(
            '<info>%d feeds read, %d new items, %d posts.</info>',
            $run->getFeedsRead(),
            $run->getItemsAdded(),
            $run->getPostsCreated()
        ));

        return $run->isSuccess() ? Command::SUCCESS : Command::FAILURE;
    }
}
