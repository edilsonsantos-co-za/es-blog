<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Category;
use App\Service\CategoryService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_EDITOR')]
class CategoryController extends AbstractController
{
    public function __construct(
        private readonly CategoryService $categoryService,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('/admin/categories', name: 'app_admin_categories', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/category/index.html.twig', [
            'categories' => $this->categoryService->findAllWithCounts(),
        ]);
    }

    #[Route('/admin/category/new', name: 'app_admin_category_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('category_form', $request->request->get('_token'))) {
                $this->addFlash('error', 'Invalid CSRF token.');
                return $this->redirectToRoute('app_admin_categories');
            }

            $title = trim($request->request->get('title', ''));
            if ($title === '') {
                $this->addFlash('error', 'Category title is required.');
                return $this->redirectToRoute('app_admin_categories');
            }

            try {
                $slug        = trim($request->request->get('slug', ''));
                $description = trim($request->request->get('description', ''));
                $this->categoryService->create($title, $description ?: null, $slug ?: null);
                $this->addFlash('success', 'Category created.');
            } catch (\Exception $e) {
                $this->addFlash('error', 'Error: ' . $e->getMessage());
            }

            return $this->redirectToRoute('app_admin_categories');
        }

        return $this->render('admin/category/index.html.twig', [
            'categories' => $this->categoryService->findAllWithCounts(),
            'show_form'  => true,
        ]);
    }

    #[Route('/admin/category/{id}/edit', name: 'app_admin_category_edit', methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request): Response
    {
        $category = $this->em->find(Category::class, $id);
        if ($category === null) {
            throw $this->createNotFoundException('Category not found.');
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('category_form', $request->request->get('_token'))) {
                $this->addFlash('error', 'Invalid CSRF token.');
                return $this->redirectToRoute('app_admin_categories');
            }

            $title = trim($request->request->get('title', ''));
            if ($title === '') {
                $this->addFlash('error', 'Category title is required.');
                return $this->redirectToRoute('app_admin_category_edit', ['id' => $id]);
            }

            try {
                $slug        = trim($request->request->get('slug', ''));
                $description = trim($request->request->get('description', ''));
                $this->categoryService->update($category, $title, $description ?: null, $slug ?: null);
                $this->addFlash('success', 'Category updated.');
                return $this->redirectToRoute('app_admin_categories');
            } catch (\Exception $e) {
                $this->addFlash('error', 'Error: ' . $e->getMessage());
            }
        }

        return $this->render('admin/category/index.html.twig', [
            'categories'    => $this->categoryService->findAllWithCounts(),
            'edit_category' => $category,
        ]);
    }

    #[Route('/admin/category/{id}/delete', name: 'app_admin_category_delete', methods: ['POST'])]
    public function delete(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('category_delete_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_admin_categories');
        }

        $category = $this->em->find(Category::class, $id);
        if ($category === null) {
            throw $this->createNotFoundException('Category not found.');
        }

        try {
            $this->categoryService->delete($category);
            $this->addFlash('success', 'Category deleted.');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Cannot delete category: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_categories');
    }
}
