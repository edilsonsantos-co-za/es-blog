<?php

declare(strict_types=1);

namespace App\Controller\Blog;

use App\Service\CategoryService;
use App\Service\PostService;
use App\Service\SiteConfigService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class CategoryController extends AbstractController
{
    public function __construct(
        private readonly CategoryService $categoryService,
        private readonly PostService $postService,
        private readonly SiteConfigService $siteConfig,
    ) {}

    #[Route('/category/{slug}', name: 'app_blog_category', methods: ['GET'])]
    public function index(Request $request, string $slug): Response
    {
        $category = $this->categoryService->findBySlug($slug);

        if ($category === null) {
            throw $this->createNotFoundException('Category not found.');
        }

        $page    = max(1, $request->query->getInt('page', 1));
        $perPage = $this->siteConfig->getInt('blog.post.perpage', 10);
        $posts   = $this->postService->paginateByCategory($category, $page, $perPage);

        return $this->render('blog/category/index.html.twig', [
            'category' => $category,
            'posts'    => $posts,
        ]);
    }
}
