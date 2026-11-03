<?php

declare(strict_types=1);

namespace App\Controller\Blog;

use App\Enum\PostType;
use App\Service\PostService;
use App\Service\SiteConfigService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class IndexController extends AbstractController
{
    public function __construct(
        private readonly PostService $postService,
        private readonly SiteConfigService $siteConfig,
    ) {}

    /** The landing page is the hero bento only; the full list lives on /posts. */
    #[Route('/', name: 'app_blog_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        // Old paginated home links (/?page=2) now belong to the Writing page.
        if ($request->query->has('page')) {
            return $this->redirectToRoute('app_blog_posts', ['page' => max(1, $request->query->getInt('page', 1))], Response::HTTP_MOVED_PERMANENTLY);
        }

        return $this->render('blog/index.html.twig', [
            'posts' => $this->postService->paginate(1, 3, PostType::Post),
        ]);
    }

    #[Route('/posts', name: 'app_blog_posts', methods: ['GET'])]
    public function posts(Request $request): Response
    {
        $page    = max(1, $request->query->getInt('page', 1));
        $perPage = $this->siteConfig->getInt('blog.post.perpage', 10);

        return $this->render('blog/posts.html.twig', [
            'posts' => $this->postService->paginate($page, $perPage, PostType::Post),
        ]);
    }
}
