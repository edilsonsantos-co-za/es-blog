<?php

declare(strict_types=1);

namespace App\Controller\Blog;

use App\Service\PostService;
use App\Service\SiteConfigService;
use App\Service\TagService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class TagController extends AbstractController
{
    public function __construct(
        private readonly TagService $tagService,
        private readonly PostService $postService,
        private readonly SiteConfigService $siteConfig,
    ) {}

    #[Route('/tag/{slug}', name: 'app_blog_tag', methods: ['GET'])]
    public function index(Request $request, string $slug): Response
    {
        $tag = $this->tagService->findBySlug($slug);

        if ($tag === null) {
            throw $this->createNotFoundException('Tag not found.');
        }

        $page    = max(1, $request->query->getInt('page', 1));
        $perPage = $this->siteConfig->getInt('blog.post.perpage', 10);
        $posts   = $this->postService->paginateByTag($tag, $page, $perPage);

        return $this->render('blog/tag/index.html.twig', [
            'tag'   => $tag,
            'posts' => $posts,
        ]);
    }
}
