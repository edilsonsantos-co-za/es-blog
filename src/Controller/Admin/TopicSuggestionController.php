<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\TopicSuggestion;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Topics readers suggested on the search page: open ones first, then the handled ones. */
#[IsGranted('ROLE_EDITOR')]
final class TopicSuggestionController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    #[Route('/admin/suggestions', name: 'app_admin_suggestions', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $repo = $this->em->getRepository(TopicSuggestion::class);
        $rows = $this->em->createQueryBuilder()->select('s')->from(TopicSuggestion::class, 's')
            ->addSelect('CASE WHEN s.handledAt IS NULL THEN 0 ELSE 1 END AS HIDDEN done')
            ->orderBy('done', 'ASC')->addOrderBy('s.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * 50)->setMaxResults(50)->getQuery()->getResult();

        return $this->render('admin/suggestions/index.html.twig', [
            'suggestions' => $rows, 'total' => $repo->count([]), 'open' => $repo->count(['handledAt' => null]), 'page' => $page,
        ], new Response('', 200, ['Cache-Control' => 'no-store']));
    }

    #[Route('/admin/suggestions/{id}/toggle', name: 'app_admin_suggestions_toggle', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function toggle(int $id, Request $request): Response
    {
        $this->guard($request, 'suggestion_toggle_' . $id);
        $s = $this->em->find(TopicSuggestion::class, $id) ?? throw $this->createNotFoundException();
        $s->handledAt = $s->handledAt ? null : new \DateTimeImmutable();
        $this->em->flush();
        $this->addFlash('success', $s->handledAt ? 'Marked as done.' : 'Reopened.');

        return $this->redirectToRoute('app_admin_suggestions');
    }

    #[Route('/admin/suggestions/{id}/delete', name: 'app_admin_suggestions_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(int $id, Request $request): Response
    {
        $this->guard($request, 'suggestion_delete_' . $id);
        $s = $this->em->find(TopicSuggestion::class, $id) ?? throw $this->createNotFoundException();
        $this->em->remove($s);
        $this->em->flush();
        $this->addFlash('success', 'Suggestion deleted.');

        return $this->redirectToRoute('app_admin_suggestions');
    }

    private function guard(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid form token.');
        }
    }
}
