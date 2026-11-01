<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Enum\PostStatus;
use App\Service\PostService;
use App\Service\SiteConfigService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_AUTHOR')]
class DashboardController extends AbstractController
{
    #[Route('/admin', name: 'app_admin_dashboard', methods: ['GET'])]
    public function index(PostService $postService, SiteConfigService $siteConfigService): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->render('admin/dashboard.html.twig', [
            'recent_posts' => $postService->paginateAdmin(1, 10, null, $user),
            'stats'        => [
                'published' => $postService->countByStatus(PostStatus::Published),
                'draft'     => $postService->countByStatus(PostStatus::Draft),
                'scheduled' => $postService->countByStatus(PostStatus::Scheduled),
            ],
            'site_title'   => $siteConfigService->get('site.title', 'ES Blog'),
        ]);
    }
}
