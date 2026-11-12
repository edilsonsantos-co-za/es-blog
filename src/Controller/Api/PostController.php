<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Post;
use App\Enum\PostType;
use App\Service\PostService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class PostController extends AbstractController
{
    public function __construct(
        private readonly PostService $postService,
    ) {}

    #[Route('/api/posts', name: 'app_api_posts', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $page    = max(1, $request->query->getInt('page', 1));
        $perPage = min(100, max(1, $request->query->getInt('per_page', 20)));
        $posts   = $this->postService->paginate($page, $perPage, PostType::Post);

        $data = array_map(static function (Post $post): array {
            $pubAt = $post->getPublishedAt() ?? $post->getCreatedAt();

            return [
                'id'       => $post->getId(),
                'title'    => $post->getTitle(),
                'slug'     => $post->getSlug(),
                'excerpt'  => $post->getDescription() ?? mb_substr(strip_tags($post->getBody()), 0, 300),
                'author'   => $post->getAuthor()->getUsername(),
                'author_name' => $post->getAuthor()->getDisplayName(),
                'date'     => $pubAt->format('Y-m-d\TH:i:sP'),
                'tags'     => array_map(static fn ($t) => $t->getName(), $post->getTags()->toArray()),
                'category' => $post->getCategory()?->getTitle(),
            ];
        }, iterator_to_array($posts));

        return $this->json([
            'data'  => $data,
            'total' => $posts->getTotalItemCount(),
            'page'  => $page,
            'per_page' => $perPage,
        ]);
    }
}
