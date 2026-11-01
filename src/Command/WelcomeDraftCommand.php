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
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:post:welcome-draft', description: 'Prepare a review-only launch article and social drafts, without publishing')]
final class WelcomeDraftCommand extends Command
{
    public function __construct(
        private readonly PostService $posts,
        private readonly UserRepository $users,
        private readonly BannerGenerator $banners,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $slug = 'building-in-the-open-es-apps';
        if ($this->posts->findBySlug($slug) !== null) {
            $output->writeln('Welcome article already exists; no content was overwritten.');

            return Command::SUCCESS;
        }
        $author = $this->users->findOneBy(['role' => UserRole::Admin], ['id' => 'ASC']);
        if ($author === null) {
            $output->writeln('<error>Create the blog admin before preparing this draft.</error>');

            return Command::FAILURE;
        }
        $title = 'Building in the open: the ES apps and what comes next';
        $post = $this->posts->create([
            'slug' => $slug,
            'title' => $title,
            'status' => PostStatus::Draft,
            'description' => 'A starting point for the ES apps: self-hosted tools, practical engineering notes and a transparent roadmap.',
            'image' => $this->banners->generate($title, 'Building in the open', $slug, 'ES Blog'),
            'tags' => 'open source, self hosting, engineering',
            'linkedin_text' => "The ES apps bring together a self-hosted portfolio, a developer blog and a private journal.\n\nThe next step is sharing practical engineering notes and documenting how these tools fit together: deployment, themes, publishing and the trade-offs behind each feature.\n\nThe roadmap is a work in progress, not a list of promises already delivered. Feedback and contributions will help shape it.\n\n#OpenSource #SelfHosting #SoftwareEngineering",
            'twitter_text' => 'Building in the open: the ES apps connect a self-hosted portfolio, developer blog and private journal. Follow along for practical engineering notes, documented trade-offs and a transparent roadmap. #OpenSource',
            'body' => <<<'MARKDOWN'
The ES apps are a growing set of self-hosted tools for presenting projects, publishing ideas and keeping a private journal. This blog is where their development can become a conversation rather than just a collection of commits.

## A small ecosystem, with distinct jobs

**ES Portfolio** is the project showcase. It is built on Laravel and brings project information, themes and account access together.

**ES Blog** is the publishing workspace. The Symfony application keeps articles in drafts until they are reviewed, and connects longer explanations with shorter platform-specific posts.

**ES Journal** is based on BookStack and is deliberately private. Describing the application publicly does not mean exposing journal entries.

**ES Labs** holds experiments and prototypes. An experiment is not a production promise: useful lessons deserve documentation even when a prototype does not become a product.

## What to expect here

The focus is practical engineering: how an application is deployed, where a design becomes unnecessarily complicated, how changes are tested, and what happens when an integration meets real-world constraints.

Some posts will cover changes in the wider developer ecosystem. Those articles should combine related sources into original analysis, credit the reporting they depend on and distinguish supported facts from interpretation. AI can help prepare a draft; it is not a substitute for checking sources or taking responsibility for what is published.

Other posts will explain changes to the ES apps themselves. Readers should be able to understand both a feature and the trade-off behind it, whether they use a hosted instance or eventually run the tools themselves.

## Openness without pretending everything is ready

The intention is to make the projects easier to inspect, install and contribute to. Public developer documentation is part of that work. Repository publication also needs care: licences must be respected, historical credentials checked, and installation instructions tested before calling a project ready.

Themes, templates and a possible digital-asset store are still being considered. They are not available for purchase yet. Instance registration and automated updates need an explicit privacy and consent design before they collect information or change anybody's installation.

## Follow the work on your terms

The blog is the home for complete articles. LinkedIn and X are intended for shorter updates that link back to the explanation.

Newsletter signup is optional. Blog articles and product updates have separate preferences, and every email must provide a way to unsubscribe. Forking a repository should never silently enrol someone in marketing mail.

## Before this draft is published

[AUTHOR REVIEW: introduce your own motivation in your own words, verify the live Developers link and signup flow, preview the banner, and decide which social channels are connected. Remove this section after review.]
MARKDOWN,
        ], $author);
        $output->writeln(sprintf('Prepared draft #%d. Review /admin/post/%d/preview; nothing was published or shared.', $post->getId(), $post->getId()));

        return Command::SUCCESS;
    }
}
