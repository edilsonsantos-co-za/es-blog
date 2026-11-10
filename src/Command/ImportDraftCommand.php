<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\PostStatus;
use App\Enum\UserRole;
use App\Repository\UserRepository;
use App\Service\Automation\BannerGenerator;
use App\Service\PostService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Saves a Markdown file with YAML front matter as a draft post (never published, never overwrites).
 *
 * Front matter: title (required), slug, description, tags, kicker (banner label), linkedin, twitter.
 * The draft text can live outside this repository until it is published.
 */
#[AsCommand(name: 'app:post:import-draft', description: 'Save a Markdown file with front matter as a draft post (never publishes, never overwrites)')]
final class ImportDraftCommand extends Command
{
    public function __construct(
        private readonly PostService $posts,
        private readonly UserRepository $users,
        private readonly BannerGenerator $banners,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::REQUIRED, 'Markdown file with YAML front matter, or - for stdin');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = (string) $input->getArgument('file');
        $raw = $file === '-' ? (string) stream_get_contents(STDIN) : (string) @file_get_contents($file);
        if (!preg_match('/\A---\R(.*?)\R---\R(.*)\z/s', $raw, $m)) {
            $output->writeln('<error>The file needs YAML front matter between --- lines, then the Markdown body.</error>');

            return Command::FAILURE;
        }
        $meta = Yaml::parse($m[1]);
        $body = trim($m[2]) . "\n";
        $title = trim((string) ($meta['title'] ?? ''));
        if ($title === '' || trim($body) === '') {
            $output->writeln('<error>A title and a body are required.</error>');

            return Command::FAILURE;
        }
        $slug = trim((string) ($meta['slug'] ?? ''));
        if ($slug !== '' && $this->posts->findBySlug($slug) !== null) {
            $output->writeln(sprintf('A post with the slug "%s" already exists; nothing was changed.', $slug));

            return Command::SUCCESS;
        }
        $author = $this->users->findOneBy(['role' => UserRole::Admin], ['id' => 'ASC']);
        if ($author === null) {
            $output->writeln('<error>Create the blog admin before importing a draft.</error>');

            return Command::FAILURE;
        }

        $data = [
            'title' => $title,
            'status' => PostStatus::Draft,
            'body' => $body,
            'description' => (string) ($meta['description'] ?? ''),
            'tags' => is_array($meta['tags'] ?? null) ? implode(', ', $meta['tags']) : (string) ($meta['tags'] ?? ''),
            'linkedin_text' => trim((string) ($meta['linkedin'] ?? '')),
            'twitter_text' => trim((string) ($meta['twitter'] ?? '')),
        ];
        if ($slug !== '') {
            $data['slug'] = $slug;
        }
        $data['image'] = $this->banners->generate($title, (string) ($meta['kicker'] ?? 'Article'), $slug !== '' ? $slug : md5($title));

        $post = $this->posts->create($data, $author);
        $output->writeln(sprintf('Saved draft #%d "%s". Review /admin/post/%d/preview; nothing was published or shared.', $post->getId(), $title, $post->getId()));

        return Command::SUCCESS;
    }
}
