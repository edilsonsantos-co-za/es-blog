<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\AppVersion;
use App\Service\MenuService;
use App\Service\PostService;
use App\Service\SiteConfigService;
use App\Service\TagService;
use App\Service\CategoryService;
use League\CommonMark\CommonMarkConverter;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\ExternalLink\ExternalLinkExtension;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Twig extension that injects blog-global variables and helper functions
 * into every template — replacing the procedural global helpers in v1's htmly.php.
 *
 * Global variables available in all templates:
 *   {{ site_title }}, {{ site_description }}, {{ site_url }}, {{ site_theme }}
 *   {{ menu_items }}  (top-level MenuItem[] with children)
 *   {{ recent_posts }} (last 5 published posts)
 *   {{ tag_cloud }}   (all tags with counts)
 *   {{ categories }}  (all categories)
 *   {{ app_version }}, {{ app_release_tag }}
 *
 * Functions:
 *   {{ config('site.title') }}
 *   {{ reading_time(post.body) }}
 *   {{ excerpt(post.body, 300) }}
 *
 * Filters:
 *   {{ post.body | markdown }}
 *   {{ post.publishedAt | ago }}
 */
final class BlogExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private readonly SiteConfigService $config,
        private readonly MenuService $menu,
        private readonly PostService $posts,
        private readonly TagService $tags,
        private readonly CategoryService $categories,
        private readonly AppVersion $appVersion,
    ) {}

    public function getGlobals(): array
    {
        $version = $this->appVersion->getVersion();

        return [
            'site_title'       => $this->config->get('site.title', 'EdFolio Blog'),
            'site_description' => $this->config->get('site.description', ''),
            'site_url'         => $this->config->get('site.url', ''),
            'site_theme'       => $this->config->get('theme', 'casper-2.1'),
            'site_portfolio_url' => $this->config->get('site.portfolio_url', ''),
            'site_logo'        => $this->config->get('site.logo', ''),
            'site_cover'       => $this->config->get('site.cover', ''),
            'posts_per_page'   => $this->config->getInt('blog.post.perpage', 10),
            'menu_items'       => $this->menu->getMenu(),
            'recent_posts'     => $this->posts->findLatest(5),
            'all_categories'   => $this->categories->findAll(),
            'tag_cloud'        => $this->tags->findAllWithCounts(),
            'app_version'      => $version,
            'app_release_tag'  => $this->appVersion->getReleaseTag(),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('config', [$this->config, 'get']),
            new TwigFunction('reading_time', $this->readingTime(...)),
            new TwigFunction('excerpt', $this->excerpt(...)),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('markdown', $this->markdown(...), ['is_safe' => ['html']]),
            new TwigFilter('ago', $this->ago(...)),
            new TwigFilter('reading_time', $this->readingTime(...)),
        ];
    }

    /**
     * Convert Markdown (GitHub Flavoured) to safe HTML.
     * External links open in a new tab with rel="noopener noreferrer".
     */
    public function markdown(string $text): string
    {
        $environment = new Environment([
            'html_input'         => 'escape',
            'allow_unsafe_links' => false,
            'external_link'      => [
                'internal_hosts'     => $this->config->get('site.url', ''),
                'open_in_new_window' => true,
                'html_class'         => 'external-link',
                'nofollow'           => '',
                'noopener'           => 'external',
                'noreferrer'         => 'external',
            ],
        ]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new GithubFlavoredMarkdownExtension());
        $environment->addExtension(new ExternalLinkExtension());

        return (new CommonMarkConverter([], $environment))->convert($text)->getContent();
    }

    /**
     * Estimate reading time in minutes based on 200 wpm.
     */
    public function readingTime(string $body): int
    {
        $wordCount = str_word_count(strip_tags($body));

        return max(1, (int) round($wordCount / 200));
    }

    /**
     * Return plain-text excerpt of $length characters.
     */
    public function excerpt(string $body, int $length = 300): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5)));
        if (mb_strlen($text) <= $length) {
            return $text;
        }

        return mb_substr($text, 0, $length) . '…';
    }

    /**
     * Human-readable relative time: "3 days ago", "just now", etc.
     */
    public function ago(\DateTimeInterface $date): string
    {
        $now  = new \DateTime();
        $diff = $now->getTimestamp() - $date->getTimestamp();

        return match (true) {
            $diff < 60     => 'just now',
            $diff < 3600   => floor($diff / 60) . ' minutes ago',
            $diff < 86400  => floor($diff / 3600) . ' hours ago',
            $diff < 604800 => floor($diff / 86400) . ' days ago',
            $diff < 2592000 => floor($diff / 604800) . ' weeks ago',
            $diff < 31536000 => floor($diff / 2592000) . ' months ago',
            default          => floor($diff / 31536000) . ' years ago',
        };
    }
}
