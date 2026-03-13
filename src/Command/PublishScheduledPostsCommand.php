<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\PublishScheduledPostsMessage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:publish-scheduled',
    description: 'Dispatch job to publish scheduled posts',
)]
final class PublishScheduledPostsCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->bus->dispatch(new PublishScheduledPostsMessage());

        $output->writeln('<info>PublishScheduledPostsMessage dispatched successfully.</info>');

        return Command::SUCCESS;
    }
}
