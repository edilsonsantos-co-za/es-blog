<?php

declare(strict_types=1);

namespace App\Controller\Blog;

use App\Service\PostService;
use App\Service\SiteConfigService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ArchiveController extends AbstractController
{
    public function __construct(
        private readonly PostService $postService,
        private readonly SiteConfigService $siteConfig,
    ) {}

    #[Route('/archive/{year}', name: 'app_blog_archive_year', methods: ['GET'], requirements: ['year' => '\d{4}'])]
    public function year(Request $request, int $year): Response
    {
        $page    = max(1, $request->query->getInt('page', 1));
        $perPage = $this->siteConfig->getInt('blog.post.perpage', 10);
        $posts   = $this->postService->paginateArchive($year, null, $page, $perPage);

        return $this->render('blog/archive/index.html.twig', [
            'posts' => $posts,
            'year'  => $year,
            'month' => null,
        ]);
    }

    #[Route('/archive/{year}/{month}', name: 'app_blog_archive_month', methods: ['GET'], requirements: ['year' => '\d{4}', 'month' => '\d{2}'])]
    public function month(Request $request, int $year, int $month): Response
    {
        $page    = max(1, $request->query->getInt('page', 1));
        $perPage = $this->siteConfig->getInt('blog.post.perpage', 10);
        $posts   = $this->postService->paginateArchive($year, $month, $page, $perPage);

        return $this->render('blog/archive/index.html.twig', [
            'posts' => $posts,
            'year'  => $year,
            'month' => $month,
        ]);
    }
}
