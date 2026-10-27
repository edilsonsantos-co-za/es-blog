<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\SiteConfigService;
use App\Service\Theme\InstalledThemes;
use App\Service\Theme\ThemeInstaller;
use App\Service\Theme\ThemePackageException;
use App\Service\Theme\ThemeStore;
use App\Twig\BlogExtension;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin -> Themes: install ES Marketplace themes from the store or from a
 * downloaded package, switch between them, and remove them. Templates are
 * code, so this is admin-only and packages must be signed.
 */
#[IsGranted('ROLE_ADMIN')]
class ThemeController extends AbstractController
{
    public function __construct(
        private readonly InstalledThemes $themes,
        private readonly ThemeInstaller $installer,
        private readonly ThemeStore $store,
        private readonly SiteConfigService $config,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/admin/themes', name: 'app_admin_themes', methods: ['GET'])]
    public function index(): Response
    {
        $installed = $this->themes->all();
        $versions = array_column($installed, 'version', 'id');

        $storeThemes = [];
        $storeError = null;
        try {
            foreach ($this->store->themes() as $theme) {
                $current = $versions[$theme['id']] ?? null;
                $theme['state'] = !array_key_exists($theme['id'], $versions) ? 'new'
                    : ($current !== null && version_compare($theme['version'], $current, '>') ? 'update' : 'installed');
                $storeThemes[] = $theme;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Theme store unavailable: {message}', ['message' => $e->getMessage()]);
            $storeError = 'The ES Marketplace store could not be reached. You can still upload a downloaded package.';
        }

        return $this->render('admin/theme/index.html.twig', [
            'installed' => $installed,
            'active' => $this->config->get('site.theme', InstalledThemes::DEFAULT),
            'store_themes' => $storeThemes,
            'store_error' => $storeError,
            'store_url' => rtrim((string) $this->config->get('site.store_url', BlogExtension::DEFAULT_STORE_URL), '/'),
        ]);
    }

    #[Route('/admin/themes/install/{id}', name: 'app_admin_themes_install', requirements: ['id' => '[a-z0-9][a-z0-9-]*'], methods: ['POST'])]
    public function installFromStore(Request $request, string $id): Response
    {
        if (!$this->isCsrfTokenValid('theme_install', $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_admin_themes');
        }

        $file = null;
        try {
            $file = $this->store->download($id);
            $this->finishInstall($file, $request->request->getBoolean('activate'));
        } catch (ThemePackageException $e) {
            $this->addFlash('error', $e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('Theme install failed: {message}', ['message' => $e->getMessage(), 'id' => $id]);
            $this->addFlash('error', 'The theme could not be installed. Check the logs for details.');
        } finally {
            if ($file !== null) {
                @unlink($file);
            }
        }

        return $this->redirectToRoute('app_admin_themes');
    }

    #[Route('/admin/themes/upload', name: 'app_admin_themes_upload', methods: ['POST'])]
    public function upload(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('theme_upload', $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_admin_themes');
        }

        $upload = $request->files->get('package');
        if ($upload === null || !$upload->isValid()) {
            $this->addFlash('error', 'Choose a theme package (.zip) downloaded from the ES Marketplace store.');
            return $this->redirectToRoute('app_admin_themes');
        }

        try {
            $this->finishInstall($upload->getPathname(), $request->request->getBoolean('activate'));
        } catch (ThemePackageException $e) {
            $this->addFlash('error', $e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('Theme upload failed: {message}', ['message' => $e->getMessage()]);
            $this->addFlash('error', 'The theme could not be installed. Check the logs for details.');
        }

        return $this->redirectToRoute('app_admin_themes');
    }

    #[Route('/admin/themes/{id}/activate', name: 'app_admin_themes_activate', requirements: ['id' => '[a-z0-9][a-z0-9-]*'], methods: ['POST'])]
    public function activate(Request $request, string $id): Response
    {
        if (!$this->isCsrfTokenValid('theme_activate', $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
        } elseif (!$this->themes->has($id)) {
            $this->addFlash('error', 'That theme is not installed.');
        } else {
            $this->config->set('site.theme', $id);
            $this->addFlash('success', sprintf('%s is now the public theme.', $this->themes->get($id)['name']));
        }

        return $this->redirectToRoute('app_admin_themes');
    }

    #[Route('/admin/themes/{id}/remove', name: 'app_admin_themes_remove', requirements: ['id' => '[a-z0-9][a-z0-9-]*'], methods: ['POST'])]
    public function remove(Request $request, string $id): Response
    {
        if (!$this->isCsrfTokenValid('theme_remove', $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_admin_themes');
        }

        try {
            $name = $this->themes->get($id)['name'] ?? $id;
            if ($this->config->get('site.theme', InstalledThemes::DEFAULT) === $id) {
                $this->config->set('site.theme', InstalledThemes::DEFAULT);
            }
            $this->installer->remove($id, $this->who());
            $this->addFlash('success', sprintf('%s was removed.', $name));
        } catch (ThemePackageException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_themes');
    }

    private function finishInstall(string $path, bool $activate): void
    {
        $manifest = $this->installer->install($path, $this->who());
        if ($activate) {
            $this->config->set('site.theme', $manifest['id']);
        }
        $this->addFlash('success', sprintf('%s %s installed%s.', $manifest['name'], $manifest['version'], $activate ? ' and activated' : ''));
    }

    private function who(): string
    {
        return $this->getUser()?->getUserIdentifier() ?? 'unknown';
    }
}
