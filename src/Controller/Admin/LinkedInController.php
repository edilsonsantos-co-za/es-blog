<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class LinkedInController extends AbstractController
{
    #[Route('/admin/linkedin', name: 'app_admin_linkedin', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/linkedin/index.html.twig');
    }
}
