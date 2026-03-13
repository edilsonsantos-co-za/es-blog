<?php

declare(strict_types=1);

namespace App\Controller\Blog;

use App\Service\PostService;
use App\Service\SiteConfigService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SearchController extends AbstractController
{
    public function __construct(
        private readonly PostService $postService,
        private readonly SiteConfigService $siteConfig,
    ) {}

    #[Route('/search', name: 'app_blog_search', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $query   = trim($request->query->getString('q', ''));
        $page    = max(1, $request->query->getInt('page', 1));
        $perPage = $this->siteConfig->getInt('blog.post.perpage', 10);
        $posts   = null;

        if ($query !== '') {
            $posts = $this->postService->search($query, $page, $perPage);
        }

        return $this->render('blog/search/index.html.twig', [
            'q'     => $query,
            'posts' => $posts,
        ]);
    }
}
