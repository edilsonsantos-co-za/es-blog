<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\MenuItem;
use App\Service\MenuService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class MenuController extends AbstractController
{
    public function __construct(
        private readonly MenuService $menuService,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('/admin/menu', name: 'app_admin_menu', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('menu_form', $request->request->get('_token'))) {
                $this->addFlash('error', 'Invalid CSRF token.');
                return $this->redirectToRoute('app_admin_menu');
            }

            $name   = trim($request->request->get('name', ''));
            $slug   = trim($request->request->get('slug', ''));
            $target = trim($request->request->get('target', ''));
            $class  = trim($request->request->get('class', ''));

            if ($name === '' || $slug === '') {
                $this->addFlash('error', 'Name and URL/slug are required.');
                return $this->redirectToRoute('app_admin_menu');
            }

            $parentId = $request->request->get('parent_id', '');
            $parent = null;
            if ($parentId !== '') {
                $parent = $this->em->find(MenuItem::class, (int) $parentId);
            }

            $this->menuService->create(
                $name,
                $slug,
                $parent,
                $class ?: null,
                $target ?: null,
                count($this->menuService->findAll()),
            );

            $this->addFlash('success', 'Menu item added.');
            return $this->redirectToRoute('app_admin_menu');
        }

        return $this->render('admin/menu/index.html.twig', [
            'items' => $this->menuService->findAll(),
        ]);
    }

    #[Route('/admin/menu/{id}/edit', name: 'app_admin_menu_edit', methods: ['POST'])]
    public function edit(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('menu_edit_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_admin_menu');
        }

        $item = $this->em->find(MenuItem::class, $id);
        if ($item === null) {
            throw $this->createNotFoundException('Menu item not found.');
        }

        $parentId = $request->request->get('parent_id', '');
        $parent = null;
        if ($parentId !== '') {
            $parent = $this->em->find(MenuItem::class, (int) $parentId);
        }

        $this->menuService->update($item, [
            'name'   => trim($request->request->get('name', $item->getName())),
            'slug'   => trim($request->request->get('slug', $item->getSlug())),
            'target' => trim($request->request->get('target', '')),
            'class'  => trim($request->request->get('class', '')),
            'parent' => $parent,
        ]);

        $this->addFlash('success', 'Menu item updated.');
        return $this->redirectToRoute('app_admin_menu');
    }

    #[Route('/admin/menu/{id}/delete', name: 'app_admin_menu_delete', methods: ['POST'])]
    public function delete(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('menu_delete_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_admin_menu');
        }

        $item = $this->em->find(MenuItem::class, $id);
        if ($item === null) {
            throw $this->createNotFoundException('Menu item not found.');
        }

        $this->menuService->delete($item);
        $this->addFlash('success', 'Menu item deleted.');

        return $this->redirectToRoute('app_admin_menu');
    }

    #[Route('/admin/menu/reorder', name: 'app_admin_menu_reorder', methods: ['POST'])]
    public function reorder(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('menu_reorder', $request->request->get('_token', ''))) {
            // For JSON endpoint, also accept token from JSON body
            $content = json_decode($request->getContent(), true);
            $ids = $content['ids'] ?? [];
            if (!$this->isCsrfTokenValid('menu_reorder', $content['_token'] ?? '')) {
                return $this->json(['error' => 'Invalid CSRF token.'], 403);
            }
        } else {
            $content = json_decode($request->getContent(), true);
            $ids = $content['ids'] ?? [];
        }

        if (!is_array($ids)) {
            return $this->json(['error' => 'Invalid payload.'], 400);
        }

        $this->menuService->reorder(array_map('intval', $ids));

        return $this->json(['success' => true]);
    }
}
