<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class CacheController extends AbstractController
{
    #[Route('/admin/cache', name: 'app_admin_cache_clear', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('cache_clear', $request->request->get('_token'))) {
                $this->addFlash('error', 'Invalid CSRF token.');
                return $this->redirectToRoute('app_admin_cache_clear');
            }

            try {
                $process = new Process(['php', 'bin/console', 'cache:clear', '--env=prod', '--no-warmup']);
                $process->setWorkingDirectory($this->getParameter('kernel.project_dir'));
                $process->setTimeout(120);
                $process->run();

                if (!$process->isSuccessful()) {
                    $this->addFlash('warning', 'Cache cleared with warnings: ' . $process->getErrorOutput());
                } else {
                    $this->addFlash('success', 'Cache cleared successfully.');
                }
            } catch (\Exception $e) {
                $this->addFlash('error', 'Failed to clear cache: ' . $e->getMessage());
            }

            return $this->redirectToRoute('app_admin_cache_clear');
        }

        return $this->render('admin/cache/index.html.twig');
    }
}
