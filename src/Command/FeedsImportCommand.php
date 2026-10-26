<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\FeedSource;
use App\Repository\FeedSourceRepository;
use App\Service\Automation\FeedParser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:feeds:import',
    description: 'Import feeds from an OPML file (e.g. a Feeder.co export)',
)]
final class FeedsImportCommand extends Command
{
    public function __construct(
        private readonly FeedParser $parser,
        private readonly FeedSourceRepository $sources,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::REQUIRED, 'Path to the .opml file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = (string) $input->getArgument('file');
        if (!is_readable($path)) {
            $output->writeln("<error>Cannot read {$path}</error>");

            return Command::FAILURE;
        }

        $known = array_map(static fn (FeedSource $s): string => $s->getUrl(), $this->sources->findAll());
        $added = 0;
        foreach ($this->parser->parseOpml((string) file_get_contents($path)) as $feed) {
            if (in_array($feed['url'], $known, true)) {
                continue;
            }
            $this->em->persist(new FeedSource($feed['name'], $feed['url'], $feed['folder']));
            $known[] = $feed['url'];
            ++$added;
            $output->writeln("  + {$feed['name']}");
        }
        $this->em->flush();
        $output->writeln("<info>{$added} feeds added.</info>");

        return Command::SUCCESS;
    }
}
