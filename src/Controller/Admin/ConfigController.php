<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Security\Voter\ConfigVoter;
use App\Service\SiteConfigService;
use App\Service\Theme\InstalledThemes;
use App\Twig\BlogExtension;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ConfigController extends AbstractController
{
    public function __construct(
        private readonly SiteConfigService $configService,
        private readonly InstalledThemes $themes,
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

            $currentConfig = $this->configService->getAll();
            $theme = $this->requestValue($request, 'site_theme', $currentConfig, 'site.theme', InstalledThemes::DEFAULT);
            foreach (['site_developers_url', 'site_upwork_url', 'site_support_url'] as $field) {
                $value = trim((string) $request->request->get($field, ''));
                if ($value !== '' && (!filter_var($value, FILTER_VALIDATE_URL)
                    || !in_array(parse_url($value, PHP_URL_SCHEME), ['https', 'http'], true))) {
                    $this->addFlash('error', 'Public links must be valid HTTP(S) URLs.');
                    return $this->redirectToRoute('app_admin_config');
                }
            }

            $this->configService->setBulk([
                'site.theme'       => $this->themes->has($theme) ? $theme : InstalledThemes::DEFAULT,
                'site.title'       => $this->requestValue($request, 'site_title', $currentConfig, 'site.title', ''),
                'site.description' => $this->requestValue($request, 'site_description', $currentConfig, 'site.description', ''),
                'site.url'         => $this->requestValue($request, 'site_url', $currentConfig, 'site.url', ''),
                'site.portfolio_url' => $this->requestValue($request, 'site_portfolio_url', $currentConfig, 'site.portfolio_url', ''),
                'site.store_url'   => $this->requestValue($request, 'site_store_url', $currentConfig, 'site.store_url', BlogExtension::DEFAULT_STORE_URL),
                'site.developers_url' => $this->requestValue($request, 'site_developers_url', $currentConfig, 'site.developers_url', ''),
                'site.upwork_url' => $this->requestValue($request, 'site_upwork_url', $currentConfig, 'site.upwork_url', ''),
                'site.support_url' => $this->requestValue($request, 'site_support_url', $currentConfig, 'site.support_url', ''),
                'site.logo'        => $this->requestValue($request, 'site_logo', $currentConfig, 'site.logo', ''),
                'site.cover'       => $this->requestValue($request, 'site_cover', $currentConfig, 'site.cover', ''),
                'language'         => $this->requestValue($request, 'language', $currentConfig, 'language', 'en'),
                'timezone'         => $this->requestValue($request, 'timezone', $currentConfig, 'timezone', 'UTC'),
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
        $config = $this->configService->getAll();

        return $this->render('admin/config/index.html.twig', [
            'active_tab' => $activeTab,
            'config'     => $config,
            'installed_themes' => $this->themes->all(),
        ]);
    }

    /** @param array<string, string|null> $currentConfig */
    private function requestValue(Request $request, string $field, array $currentConfig, string $key, string $default): ?string
    {
        if (!$request->request->has($field)) {
            return $currentConfig[$key] ?? $default;
        }

        return $request->request->get($field, $default);
    }
}
