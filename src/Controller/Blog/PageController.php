<?php

declare(strict_types=1);

namespace App\Controller\Blog;

use App\Enum\PostType;
use App\Repository\PostRepository;
use App\Service\PostService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class PageController extends AbstractController
{
    public function __construct(
        private readonly PostRepository $postRepository,
    ) {}

    #[Route('/{slug}', name: 'app_blog_page', methods: ['GET'], priority: -10)]
    public function show(string $slug): Response
    {
        $post = $this->postRepository->findPublishedBySlug($slug);

        if ($post === null || $post->getType() !== PostType::Page) {
            throw $this->createNotFoundException('Page not found.');
        }

        return $this->render('blog/page/show.html.twig', [
            'post' => $post,
        ]);
    }
}
