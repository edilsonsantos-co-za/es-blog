<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\PublishScheduledPostsMessage;
use App\Service\Automation\AutomationRunner;
use App\Service\Automation\AutomationSettings;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Run by the worker container every few minutes: queues scheduled-post publishing
 * and starts the feed automation when its interval has passed.
 */
#[AsCommand(
    name: 'app:scheduler:tick',
    description: 'Publish due scheduled posts and run the feed automation when it is due',
)]
final class SchedulerTickCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly AutomationSettings $settings,
        private readonly AutomationRunner $runner,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->bus->dispatch(new PublishScheduledPostsMessage());

        if (!$this->settings->isDue()) {
            return Command::SUCCESS;
        }

        $run = $this->runner->run('schedule');
        $output->writeln(sprintf(
            '[%s] automation: %d feeds, %d new items, %d posts%s',
            $run->getStartedAt()->format('c'),
            $run->getFeedsRead(),
            $run->getItemsAdded(),
            $run->getPostsCreated(),
            $run->isSuccess() ? '' : ' (with errors)'
        ));

        return Command::SUCCESS;
    }
}
