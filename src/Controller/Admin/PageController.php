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
use App\Service\PostService;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_AUTHOR')]
class PageController extends AbstractController
{
    public function __construct(
        private readonly PostService $postService,
        private readonly CategoryService $categoryService,
        private readonly EntityManagerInterface $em,
        private readonly PaginatorInterface $paginator,
    ) {}

    #[Route('/admin/pages', name: 'app_admin_pages', methods: ['GET'])]
    public function list(Request $request, PostRepository $postRepository): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $qb = $postRepository->createQueryBuilder('p')
            ->where('p.type IN (:types)')
            ->setParameter('types', [PostType::Page, PostType::Subpage])
            ->orderBy('p.type', 'ASC')
            ->addOrderBy('p.title', 'ASC');
        $pagination = $this->paginator->paginate($qb, $page, 50);

        return $this->render('admin/page/list.html.twig', [
            'pages' => $pagination,
        ]);
    }

    #[Route('/admin/page/new', name: 'app_admin_page_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $this->denyAccessUnlessGranted(PostVoter::CREATE);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('post_form', $request->request->get('_token'))) {
                $this->addFlash('error', 'Invalid CSRF token.');
                return $this->redirectToRoute('app_admin_page_new');
            }

            try {
                /** @var User $user */
                $user = $this->getUser();
                $data = $this->extractPageData($request);
                $post = $this->postService->create($data, $user);
                $this->addFlash('success', 'Page created successfully.');
                return $this->redirectToRoute('app_admin_page_edit', ['id' => $post->getId()]);
            } catch (\Exception $e) {
                $this->addFlash('error', 'Error creating page: ' . $e->getMessage());
            }
        }

        return $this->render('admin/post/form.html.twig', [
            'post'       => null,
            'categories' => $this->categoryService->findAll(),
            'page_mode'  => true,
        ]);
    }

    #[Route('/admin/page/{id}/edit', name: 'app_admin_page_edit', methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request): Response
    {
        $post = $this->em->find(Post::class, $id);
        if ($post === null) {
            throw $this->createNotFoundException('Page not found.');
        }

        $this->denyAccessUnlessGranted(PostVoter::EDIT, $post);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('post_form', $request->request->get('_token'))) {
                $this->addFlash('error', 'Invalid CSRF token.');
                return $this->redirectToRoute('app_admin_page_edit', ['id' => $id]);
            }

            try {
                $data = $this->extractPageData($request);
                $this->postService->update($post, $data);
                $this->addFlash('success', 'Page updated successfully.');
                return $this->redirectToRoute('app_admin_page_edit', ['id' => $id]);
            } catch (\Exception $e) {
                $this->addFlash('error', 'Error updating page: ' . $e->getMessage());
            }
        }

        return $this->render('admin/post/form.html.twig', [
            'post'       => $post,
            'categories' => $this->categoryService->findAll(),
            'page_mode'  => true,
        ]);
    }

    #[Route('/admin/page/{id}/delete', name: 'app_admin_page_delete', methods: ['POST'])]
    public function delete(int $id, Request $request): Response
    {
        $post = $this->em->find(Post::class, $id);
        if ($post === null) {
            throw $this->createNotFoundException('Page not found.');
        }

        $this->denyAccessUnlessGranted(PostVoter::DELETE, $post);

        if (!$this->isCsrfTokenValid('post_delete_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_admin_pages');
        }

        $this->postService->delete($post);
        $this->addFlash('success', 'Page deleted.');

        return $this->redirectToRoute('app_admin_pages');
    }

    /** @return array<string, mixed> */
    private function extractPageData(Request $request): array
    {
        $typeValue = $request->request->get('type', PostType::Page->value);
        // Restrict to page/subpage types only
        if (!in_array($typeValue, [PostType::Page->value, PostType::Subpage->value], true)) {
            $typeValue = PostType::Page->value;
        }

        $publishedAt = null;
        $publishedAtRaw = $request->request->get('published_at', '');
        if ($publishedAtRaw !== '') {
            try {
                $publishedAt = new \DateTime($publishedAtRaw);
            } catch (\Exception) {
                $publishedAt = null;
            }
        }

        return [
            'title'        => $request->request->get('title', ''),
            'body'         => $request->request->get('body', ''),
            'description'  => $request->request->get('description', ''),
            'slug'         => $request->request->get('slug', ''),
            'type'         => $typeValue,
            'status'       => $request->request->get('status', PostStatus::Published->value),
            'image'        => $request->request->get('image', ''),
            'video'        => $request->request->get('video', ''),
            'audio'        => $request->request->get('audio', ''),
            'link'         => $request->request->get('link', ''),
            'quote'        => $request->request->get('quote', ''),
            'published_at' => $publishedAt,
        ];
    }
}
