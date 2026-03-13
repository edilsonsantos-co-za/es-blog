<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Security\Voter\ConfigVoter;
use App\Service\SiteConfigService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ConfigController extends AbstractController
{
    public function __construct(
        private readonly SiteConfigService $configService,
    ) {}

    #[Route('/admin/config', name: 'app_admin_config', methods: ['GET', 'POST'])]
    public function general(Request $request): Response
    {
        $this->denyAccessUnlessGranted(ConfigVoter::EDIT);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('config_general', $request->request->get('_token'))) {
                $this->addFlash('error', 'Invalid CSRF token.');
                return $this->redirectToRoute('app_admin_config');
            }

            $this->configService->setBulk([
                'site.title'       => $request->request->get('site_title', ''),
                'site.description' => $request->request->get('site_description', ''),
                'site.url'         => $request->request->get('site_url', ''),
                'site.logo'        => $request->request->get('site_logo', ''),
                'site.cover'       => $request->request->get('site_cover', ''),
                'theme'            => $request->request->get('theme', ''),
                'language'         => $request->request->get('language', 'en'),
                'admin.theme'      => $request->request->get('admin_theme', 'light'),
                'timezone'         => $request->request->get('timezone', 'UTC'),
            ]);

            $this->addFlash('success', 'General settings saved.');
            return $this->redirectToRoute('app_admin_config');
        }

        return $this->renderConfig('general');
    }

    #[Route('/admin/config/reading', name: 'app_admin_config_reading', methods: ['GET', 'POST'])]
    public function reading(Request $request): Response
    {
        $this->denyAccessUnlessGranted(ConfigVoter::EDIT);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('config_reading', $request->request->get('_token'))) {
                $this->addFlash('error', 'Invalid CSRF token.');
                return $this->redirectToRoute('app_admin_config_reading');
            }

            $this->configService->setBulk([
                'blog.post.perpage'   => $request->request->get('perpage', '10'),
                'blog.summary.type'   => $request->request->get('summary_type', 'excerpt'),
                'views.counter'       => $request->request->get('views_counter', 'false'),
            ]);

            $this->addFlash('success', 'Reading settings saved.');
            return $this->redirectToRoute('app_admin_config_reading');
        }

        return $this->renderConfig('reading');
    }

    #[Route('/admin/config/writing', name: 'app_admin_config_writing', methods: ['GET', 'POST'])]
    public function writing(Request $request): Response
    {
        $this->denyAccessUnlessGranted(ConfigVoter::EDIT);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('config_writing', $request->request->get('_token'))) {
                $this->addFlash('error', 'Invalid CSRF token.');
                return $this->redirectToRoute('app_admin_config_writing');
            }

            $this->configService->setBulk([
                'markdown.extra'    => $request->request->get('markdown_extra', 'false'),
                'thumbnail.width'   => $request->request->get('thumbnail_width', '250'),
                'twitter.username'  => $request->request->get('twitter_username', ''),
                'disqus.id'         => $request->request->get('disqus_id', ''),
                'google.analytics'  => $request->request->get('google_analytics', ''),
            ]);

            $this->addFlash('success', 'Writing settings saved.');
            return $this->redirectToRoute('app_admin_config_writing');
        }

        return $this->renderConfig('writing');
    }

    #[Route('/admin/config/security', name: 'app_admin_config_security', methods: ['GET', 'POST'])]
    public function security(Request $request): Response
    {
        $this->denyAccessUnlessGranted(ConfigVoter::EDIT);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('config_security', $request->request->get('_token'))) {
                $this->addFlash('error', 'Invalid CSRF token.');
                return $this->redirectToRoute('app_admin_config_security');
            }

            $this->configService->setBulk([
                'login.protect.system'  => $request->request->get('protect_system', 'none'),
                'login.protect.public'  => $request->request->get('protect_public', 'none'),
                'login.protect.private' => $request->request->get('protect_private', 'none'),
            ]);

            $this->addFlash('success', 'Security settings saved.');
            return $this->redirectToRoute('app_admin_config_security');
        }

        return $this->renderConfig('security');
    }

    private function renderConfig(string $activeTab): Response
    {
        return $this->render('admin/config/index.html.twig', [
            'active_tab' => $activeTab,
            'config'     => $this->configService->getAll(),
        ]);
    }
}
