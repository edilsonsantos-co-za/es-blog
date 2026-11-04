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

#[AsCommand(name: 'app:post:welcome-draft', description: 'Prepare the launch article (open-source credits) and its social drafts as a draft, without publishing')]
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
        $slug = 'thank-you-open-source';
        if ($this->posts->findBySlug($slug) !== null) {
            $output->writeln('The launch article already exists; no content was overwritten.');

            return Command::SUCCESS;
        }
        $author = $this->users->findOneBy(['role' => UserRole::Admin], ['id' => 'ASC']);
        if ($author === null) {
            $output->writeln('<error>Create the blog admin before preparing this draft.</error>');

            return Command::FAILURE;
        }
        $title = 'Built on open source: thank you to the projects behind the ES apps';
        $post = $this->posts->create([
            'slug' => $slug,
            'title' => $title,
            // Always a draft: it is published by hand once email, the repositories and the store page are ready
            'status' => PostStatus::Draft,
            'description' => 'The ES apps launch on the work of many open-source projects. This post credits them, and explains what comes next: open repositories, a newsletter and a store.',
            'image' => $this->banners->generate($title, 'Launch credits', $slug),
            'tags' => 'open source, self hosting, launch',
            'linkedin_text' => "The ES apps are live: a portfolio, this blog, a developer docs site and a private journal, all self-hosted.\n\nNone of it would exist without open source. The first post credits the projects underneath: ezfolio, HTMLy and BookStack as the starting points, Symfony, Laravel, Doctrine and Sylius as the frameworks, Docusaurus and React for the docs, Logto for sign-in, and MySQL, nginx and Docker for running it all.\n\nThe source for each app is open on GitHub, with setup guides. A store for themes and templates is coming soon, and the newsletter is the easiest way to hear when it opens.\n\n#OpenSource #SelfHosting #PHP",
            'twitter_text' => 'The ES apps are live, built on ezfolio, HTMLy, BookStack, Symfony, Laravel, Sylius, Docusaurus, Logto and more. First post: a thank-you to every project underneath. Store coming soon. #OpenSource',
            'body' => <<<'MARKDOWN'
The ES apps are now live: a portfolio, this blog, a developer documentation site and a private journal, with a store for themes and templates on the way. Every one of them stands on open-source work done by other people, often unpaid and in their spare time. Before anything else gets written here, that work deserves credit.

## Where each app started

Three of the apps began as forks of existing projects. Their licences and copyright notices are kept in each repository.

- **[ezfolio](https://github.com/arifszn/ezfolio)** by Ariful Alam (MIT) is the foundation of **ES Portfolio**: the portfolio CMS, the Laravel API and the React admin panel.
- **[HTMLy](https://github.com/danpros/htmly)** by Danang Probo Sayekti (GPL-2.0 or later) is where **ES Blog** started. The blog has since been rewritten on Symfony, but its content model of posts, pages, categories, tags and menus comes from HTMLy.
- **[BookStack](https://www.bookstackapp.com/)** by Dan Brown and the BookStack contributors (MIT) runs **ES Journal**. The journal's features live in a BookStack theme, so the core stays as upstream wrote it.

## The frameworks underneath

- **[Symfony](https://symfony.com/)**, **[Doctrine](https://www.doctrine-project.org/)** and **[Twig](https://twig.symfony.com/)** power this blog, and Symfony also sits under the store.
- **[Laravel](https://laravel.com/)** runs the portfolio and, through BookStack, the journal.
- **[Sylius](https://sylius.com/)** is the e-commerce framework behind the upcoming store.
- **[Docusaurus](https://docusaurus.io/)** and **[React](https://react.dev/)** build the developer documentation. React and **[Ant Design](https://ant.design/)** also drive the portfolio's admin panel, bundled with **[Vite](https://vite.dev/)**.

## The libraries doing quiet, essential work

A few that deserve a mention by name: **[League CommonMark](https://commonmark.thephpleague.com/)** turns Markdown into the pages you read here, **[scheb/2fa](https://github.com/scheb/2fa)** handles two-factor codes, **[CodeMirror](https://codemirror.net/)** is the code editor inside the journal, and **[Guzzle](https://docs.guzzlephp.org/)** and the Symfony HTTP client talk to other services. Many more are listed in each repository's `composer.json` and `package.json`.

## Sign-in, hosting and the bits you never see

- **[Logto](https://logto.io/)** provides single sign-on across the apps: sign in once, and the portfolio admin, the blog and the journal all know who you are.
- **[PHP](https://www.php.net/)**, **[MySQL](https://www.mysql.com/)**, **[nginx](https://nginx.org/)** and the **[Apache HTTP Server](https://httpd.apache.org/)** serve every request.
- **[Docker](https://www.docker.com/)** and Compose run each app in its own container, and **[Nginx Proxy Manager](https://nginxproxymanager.com/)** routes each domain to the right one.
- The **[Manrope](https://fonts.google.com/specimen/Manrope)**, **[JetBrains Mono](https://www.jetbrains.com/lp/mono/)** and **[Bricolage Grotesque](https://fonts.google.com/specimen/Bricolage+Grotesque)** typefaces, all under the SIL Open Font License, give the apps their look.

To every maintainer and contributor on these projects: thank you.

## The code is open too

The source of each ES app is public on [GitHub](https://github.com/edilsonsantos-co-za). The forks keep their original licences and credit their upstream projects. Each README covers running the app with Docker, and the [developer documentation](https://developers.edilsonsantos.co.za/) goes into more depth.

Sign-in uses Logto. To run an app yourself, you can host your own Logto instance or use Logto Cloud. Its [documentation](https://docs.logto.io/) walks through creating an application. Each app's README lists the redirect addresses to register and the settings to copy across.

## A store is coming soon

The **[ES Store](https://store.edilsonsantos.co.za/)** will offer themes and templates for the portfolio and the blog. It is not open for purchases yet. The free themes already in the catalogue stay free.

## Stay in the loop

The **[newsletter](/newsletter)** is the easiest way to hear when the store opens and when new articles go up. Articles and product news are separate choices, every email has a one-click unsubscribe, and nothing is sent until you confirm your address.

If any of these tools are useful to you, the best way to say thanks is to support the projects listed above. And if you would like to support this work as well, you can [buy me a coffee](https://buymeacoffee.com/edilsonsantos).
MARKDOWN,
        ], $author);
        $output->writeln(sprintf('Prepared draft #%d. Review /admin/post/%d/preview; nothing was published or shared.', $post->getId(), $post->getId()));

        return Command::SUCCESS;
    }
}
