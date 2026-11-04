<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Post;
use App\Entity\User;
use App\Enum\PostStatus;
use App\Enum\PostType;
use App\Repository\PostRepository;
use App\Security\Voter\PostVoter;
use App\Service\CategoryService;
use App\Service\Automation\BannerGenerator;
use App\Service\PostService;
use App\Service\TagService;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_AUTHOR')]
class PostController extends AbstractController
{
    public function __construct(
        private readonly PostService $postService,
        private readonly CategoryService $categoryService,
        private readonly TagService $tagService,
        private readonly EntityManagerInterface $em,
        private readonly PaginatorInterface $paginator,
    ) {}

    #[Route('/admin/posts', name: 'app_admin_posts', methods: ['GET'])]
    #[IsGranted('ROLE_EDITOR')]
    public function list(Request $request, PostRepository $postRepository): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $qb = $postRepository->createQueryBuilder('p')
            ->where('p.type = :type')
            ->setParameter('type', PostType::Post)
            ->orderBy('p.updatedAt', 'DESC');
        $pagination = $this->paginator->paginate($qb, $page, 20);

        return $this->render('admin/post/list.html.twig', [
            'posts'      => $pagination,
            'list_title' => 'All Posts',
            'route'      => 'app_admin_posts',
        ]);
    }

    #[Route('/admin/posts/mine', name: 'app_admin_posts_mine', methods: ['GET'])]
    public function mine(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $page = max(1, $request->query->getInt('page', 1));
        $pagination = $this->postService->paginateAdmin($page, 20, null, $user);

        return $this->render('admin/post/list.html.twig', [
            'posts'      => $pagination,
            'list_title' => 'My Posts',
            'route'      => 'app_admin_posts_mine',
        ]);
    }

    #[Route('/admin/posts/scheduled', name: 'app_admin_posts_scheduled', methods: ['GET'])]
    public function scheduled(Request $request): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $pagination = $this->postService->paginateAdmin($page, 20, PostStatus::Scheduled);

        return $this->render('admin/post/list.html.twig', [
            'posts'      => $pagination,
            'list_title' => 'Scheduled Posts',
            'route'      => 'app_admin_posts_scheduled',
        ]);
    }

    #[Route('/admin/posts/drafts', name: 'app_admin_posts_drafts', methods: ['GET'])]
    public function drafts(Request $request): Response
    {
        $platform = (string) $request->query->get('platform', 'blog');
        if (!in_array($platform, ['blog', 'linkedin', 'twitter'], true)) {
            throw $this->createNotFoundException('Unknown publishing platform.');
        }
        /** @var User $user */
        $user = $this->getUser();
        $page = max(1, $request->query->getInt('page', 1));
        $qb = $this->em->getRepository(Post::class)->createQueryBuilder('p')
            ->where('p.type = :type')->setParameter('type', PostType::Post)
            ->orderBy('p.updatedAt', 'DESC');
        if ($platform === 'blog') {
            $qb->andWhere('p.status = :status')->setParameter('status', PostStatus::Draft);
        } else {
            $qb->andWhere('p.status IN (:statuses)')->setParameter('statuses', [PostStatus::Draft, PostStatus::Published]);
            if ($platform === 'linkedin') {
                $qb->andWhere('p.linkedInPostUrn IS NULL');
            } else {
                $qb->andWhere("NOT EXISTS (SELECT cf.id FROM App\Entity\CustomField cf WHERE cf.post = p AND cf.fieldKey = :shared AND cf.fieldValue IS NOT NULL AND cf.fieldValue != '')")
                    ->setParameter('shared', 'twitter_post_id');
            }
        }
        if (!$this->isGranted('ROLE_EDITOR')) {
            $qb->andWhere('p.author = :author')->setParameter('author', $user);
        }
        $pagination = $this->paginator->paginate($qb, $page, 20);

        return $this->render('admin/post/list.html.twig', [
            'posts'      => $pagination,
            'list_title' => 'Draft Posts',
            'route'      => 'app_admin_posts_drafts',
            'platform'   => $platform,
        ]);
    }

    #[Route('/admin/posts/popular', name: 'app_admin_posts_popular', methods: ['GET'])]
    #[IsGranted('ROLE_EDITOR')]
    public function popular(Request $request, PostRepository $postRepository): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $qb = $postRepository->createQueryBuilder('p')
            ->where('p.status = :status')
            ->setParameter('status', PostStatus::Published)
            ->orderBy('p.views', 'DESC');
        $pagination = $this->paginator->paginate($qb, $page, 20);

        return $this->render('admin/post/list.html.twig', [
            'posts'      => $pagination,
            'list_title' => 'Popular Posts',
            'route'      => 'app_admin_posts_popular',
        ]);
    }

    #[Route('/admin/post/new', name: 'app_admin_post_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $this->denyAccessUnlessGranted(PostVoter::CREATE);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('post_form', $request->request->get('_token'))) {
                $this->addFlash('error', 'Invalid CSRF token.');
                return $this->redirectToRoute('app_admin_post_new');
            }

            try {
                /** @var User $user */
                $user = $this->getUser();
                $data = $this->extractPostData($request);
                if ($data['status'] !== PostStatus::Draft->value) {
                    $this->denyAccessUnlessGranted(PostVoter::PUBLISH, new Post());
                }
                $post = $this->postService->create($data, $user);
                $this->addFlash('success', sprintf('Post "%s" created.', $post->getTitle()));
                return $this->redirectToRoute('app_admin_posts');
            } catch (\Exception $e) {
                $this->addFlash('error', 'Error creating post: ' . $e->getMessage());
            }
        }

        return $this->render('admin/post/form.html.twig', [
            'post'       => null,
            'categories' => $this->categoryService->findAll(),
            'page_mode'  => false,
        ]);
    }

    #[Route('/admin/post/{id}/edit', name: 'app_admin_post_edit', methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request): Response
    {
        $post = $this->em->find(Post::class, $id);
        if ($post === null) {
            throw $this->createNotFoundException('Post not found.');
        }

        $this->denyAccessUnlessGranted(PostVoter::EDIT, $post);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('post_form', $request->request->get('_token'))) {
                $this->addFlash('error', 'Invalid CSRF token.');
                return $this->redirectToRoute('app_admin_post_edit', ['id' => $id]);
            }

            try {
                $data = $this->extractPostData($request);
                if ($data['status'] !== PostStatus::Draft->value && $data['status'] !== $post->getStatus()->value) {
                    $this->denyAccessUnlessGranted(PostVoter::PUBLISH, $post);
                }
                $this->postService->update($post, $data);
                $this->addFlash('success', 'Post updated successfully.');
                return $this->redirectToRoute('app_admin_post_edit', ['id' => $id]);
            } catch (\Exception $e) {
                $this->addFlash('error', 'Error updating post: ' . $e->getMessage());
            }
        }

        return $this->render('admin/post/form.html.twig', [
            'post'       => $post,
            'categories' => $this->categoryService->findAll(),
            'page_mode'  => false,
        ]);
    }

    #[Route('/admin/post/{id}/preview', name: 'app_admin_post_preview', methods: ['GET'])]
    public function preview(Post $post): Response
    {
        $this->denyAccessUnlessGranted(PostVoter::EDIT, $post);
        $response = $this->render('blog/post/preview.html.twig', ['post' => $post, 'prev' => null, 'next' => null]);
        $response->setPrivate();
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');

        return $response;
    }

    /**
     * A new 1200×630 banner from the saved title: the thumbnail the blog, LinkedIn and X previews use.
     * A fresh file name each time, so platforms that cached the old image pick up the new one.
     */
    #[Route('/admin/post/{id}/banner', name: 'app_admin_post_banner', methods: ['POST'])]
    public function banner(Post $post, Request $request, BannerGenerator $banners): Response
    {
        $this->denyAccessUnlessGranted(PostVoter::EDIT, $post);
        if (!$this->isCsrfTokenValid('post_banner_' . $post->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        $tags = $post->getTags()->toArray();
        $kicker = $post->getCategory()?->getTitle() ?? ($tags !== [] ? $tags[0]->getName() : 'ES Blog');
        $image = $banners->generate($post->getTitle(), $kicker, $post->getSlug() . '-' . base_convert((string) time(), 10, 36));
        $this->postService->update($post, ['image' => $image]);
        $this->addFlash('success', 'New banner saved. It is the thumbnail on the blog, LinkedIn and X.');

        return $this->redirectToRoute('app_admin_post_edit', ['id' => $post->getId()]);
    }

    #[Route('/admin/post/{id}/social/{platform}', name: 'app_admin_post_social_save', methods: ['POST'], requirements: ['platform' => 'linkedin|twitter'])]
    public function saveSocial(Post $post, string $platform, Request $request): Response
    {
        $this->denyAccessUnlessGranted(PostVoter::EDIT, $post);
        if (!$this->isCsrfTokenValid('post_social_' . $post->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        try {
            $this->postService->update($post, [$platform . '_text' => (string) $request->request->get('text', '')]);
            $this->addFlash('success', 'Platform draft saved. Sharing remains a separate, explicit action.');
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_posts_drafts', ['platform' => $platform]);
    }

    #[Route('/admin/post/{id}/delete', name: 'app_admin_post_delete', methods: ['POST'])]
    public function delete(int $id, Request $request): Response
    {
        $post = $this->em->find(Post::class, $id);
        if ($post === null) {
            throw $this->createNotFoundException('Post not found.');
        }

        $this->denyAccessUnlessGranted(PostVoter::DELETE, $post);

        if (!$this->isCsrfTokenValid('post_delete_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_admin_posts');
        }

        $this->postService->delete($post);
        $this->addFlash('success', 'Post deleted.');

        return $this->redirectToRoute('app_admin_posts');
    }

    /** @return array<string, mixed> */
    private function extractPostData(Request $request): array
    {
        $publishedAt = null;
        $publishedAtRaw = $request->request->get('published_at', '');
        if ($publishedAtRaw !== '') {
            try {
                $publishedAt = new \DateTime($publishedAtRaw);
            } catch (\Exception) {
                $publishedAt = null;
            }
        }

        $category = null;
        $categoryId = $request->request->get('category_id', '');
        if ($categoryId !== '') {
            $category = $this->em->find(\App\Entity\Category::class, (int) $categoryId);
        }

        return [
            'title'        => $request->request->get('title', ''),
            'body'         => $request->request->get('body', ''),
            'description'  => $request->request->get('description', ''),
            'slug'         => $request->request->get('slug', ''),
            'type'         => $request->request->get('type', PostType::Post->value),
            'status'       => $request->request->get('status', PostStatus::Draft->value),
            'category'     => $category,
            'tags'         => $request->request->get('tags', ''),
            'image'        => $request->request->get('image', ''),
            'video'        => $request->request->get('video', ''),
            'audio'        => $request->request->get('audio', ''),
            'link'         => $request->request->get('link', ''),
            'quote'        => $request->request->get('quote', ''),
            'published_at' => $publishedAt,
            'linkedin_text' => $request->request->get('linkedin_text', ''),
            'twitter_text'  => $request->request->get('twitter_text', ''),
        ];
    }
}
