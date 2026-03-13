<?php

declare(strict_types=1);

namespace App\Controller\Blog;

use App\Service\PostService;
use App\Service\SiteConfigService;
use App\Service\UserService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class AuthorController extends AbstractController
{
    public function __construct(
        private readonly UserService $userService,
        private readonly PostService $postService,
        private readonly SiteConfigService $siteConfig,
    ) {}

    #[Route('/author/{username}', name: 'app_blog_author', methods: ['GET'])]
    public function index(Request $request, string $username): Response
    {
        $author = $this->userService->findByUsername($username);

        if ($author === null) {
            throw $this->createNotFoundException('Author not found.');
        }

        $page    = max(1, $request->query->getInt('page', 1));
        $perPage = $this->siteConfig->getInt('blog.post.perpage', 10);
        $posts   = $this->postService->paginateByAuthor($author, $page, $perPage);

        return $this->render('blog/author/index.html.twig', [
            'author' => $author,
            'posts'  => $posts,
        ]);
    }
}
