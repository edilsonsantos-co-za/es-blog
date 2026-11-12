<?php

declare(strict_types=1);

namespace App\Controller\Blog;

use App\Service\PostService;
use App\Service\SiteConfigService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class PostController extends AbstractController
{
    public function __construct(
        private readonly PostService $postService,
        private readonly SiteConfigService $siteConfig,
    ) {}

    #[Route('/{year}/{month}/{slug}', name: 'app_blog_post', methods: ['GET'], requirements: ['year' => '\d{4}', 'month' => '\d{2}'])]
    public function show(int $year, int $month, string $slug): Response
    {
        $post = $this->postService->findPublishedBySlug($slug);

        if ($post === null) {
            throw $this->createNotFoundException('Post not found.');
        }

        $this->postService->incrementViews($post);

        $prev = null;
        $next = null;
        $latest = $this->postService->findLatest(20, $post);
        foreach ($latest as $i => $p) {
            if ($p->getId() < $post->getId() && $prev === null) {
                $prev = $p;
            }
            if ($p->getId() > $post->getId() && $next === null) {
                $next = $p;
            }
        }

        return $this->render('blog/post/show.html.twig', [
            'post' => $post,
            'prev' => $prev,
            'next' => $next,
        ]);
    }

    #[Route('/feed', name: 'app_blog_feed', methods: ['GET'])]
    public function feed(): Response
    {
        $posts   = $this->postService->paginate(1, 20);
        $siteUrl = rtrim((string) $this->siteConfig->get('site.url', ''), '/');
        $title   = $this->siteConfig->get('site.title', 'Blog');
        $desc    = $this->siteConfig->get('site.description', '');

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">' . "\n";
        $xml .= '<channel>' . "\n";
        $xml .= '<title>' . htmlspecialchars($title, ENT_XML1) . '</title>' . "\n";
        $xml .= '<link>' . htmlspecialchars($siteUrl, ENT_XML1) . '</link>' . "\n";
        $xml .= '<description>' . htmlspecialchars($desc, ENT_XML1) . '</description>' . "\n";
        $xml .= '<atom:link href="' . htmlspecialchars($siteUrl . '/feed', ENT_XML1) . '" rel="self" type="application/rss+xml"/>' . "\n";

        foreach ($posts as $post) {
            $pubAt = $post->getPublishedAt() ?? $post->getCreatedAt();
            $url   = $siteUrl . '/' . $pubAt->format('Y') . '/' . $pubAt->format('m') . '/' . $post->getSlug();

            $xml .= '<item>' . "\n";
            $xml .= '<title>' . htmlspecialchars($post->getTitle(), ENT_XML1) . '</title>' . "\n";
            $xml .= '<link>' . htmlspecialchars($url, ENT_XML1) . '</link>' . "\n";
            $xml .= '<guid isPermaLink="true">' . htmlspecialchars($url, ENT_XML1) . '</guid>' . "\n";
            $xml .= '<pubDate>' . $pubAt->format(\DateTime::RFC2822) . '</pubDate>' . "\n";
            $xml .= '<description><![CDATA[' . ($post->getDescription() ?? mb_substr(strip_tags($post->getBody()), 0, 300)) . ']]></description>' . "\n";
            $xml .= '<author>' . htmlspecialchars($post->getAuthor()->getDisplayName(), ENT_XML1) . '</author>' . "\n";
            $xml .= '</item>' . "\n";
        }

        $xml .= '</channel>' . "\n";
        $xml .= '</rss>';

        return new Response($xml, 200, ['Content-Type' => 'application/rss+xml; charset=UTF-8']);
    }

    #[Route('/sitemap.xml', name: 'app_blog_sitemap', methods: ['GET'])]
    public function sitemap(Request $request): Response
    {
        $siteUrl  = rtrim((string) $this->siteConfig->get('site.url', $request->getSchemeAndHttpHost()), '/');
        $posts    = $this->postService->paginate(1, 1000);

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        $xml .= '<url><loc>' . htmlspecialchars($siteUrl . '/', ENT_XML1) . '</loc><changefreq>daily</changefreq><priority>1.0</priority></url>' . "\n";

        foreach ($posts as $post) {
            $pubAt = $post->getPublishedAt() ?? $post->getCreatedAt();
            $url   = $siteUrl . '/' . $pubAt->format('Y') . '/' . $pubAt->format('m') . '/' . $post->getSlug();
            $lastmod = $post->getUpdatedAt()->format('Y-m-d');

            $xml .= '<url>';
            $xml .= '<loc>' . htmlspecialchars($url, ENT_XML1) . '</loc>';
            $xml .= '<lastmod>' . $lastmod . '</lastmod>';
            $xml .= '<changefreq>weekly</changefreq><priority>0.8</priority>';
            $xml .= '</url>' . "\n";
        }

        $xml .= '</urlset>';

        return new Response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
