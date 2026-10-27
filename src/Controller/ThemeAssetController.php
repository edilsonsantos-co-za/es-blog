<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Theme\InstalledThemes;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves an installed theme's static files from var/themes/<id>/assets/,
 * plus its preview image for the admin.
 */
final class ThemeAssetController
{
    private const TYPES = [
        'css' => 'text/css', 'js' => 'text/javascript', 'map' => 'application/json',
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
        'webp' => 'image/webp', 'svg' => 'image/svg+xml', 'ico' => 'image/x-icon',
        'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'otf' => 'font/otf',
    ];

    public function __construct(private readonly InstalledThemes $themes) {}

    #[Route('/themes/{id}/{path}', name: 'app_theme_asset', requirements: ['id' => '[a-z0-9][a-z0-9-]*', 'path' => '[A-Za-z0-9_-][A-Za-z0-9._/-]*'], methods: ['GET', 'HEAD'])]
    public function __invoke(Request $request, string $id, string $path): Response
    {
        $base = $this->themes->dir() . '/' . $id;
        $file = in_array($path, ['preview.jpg', 'preview.png'], true) ? $base . '/' . $path : $base . '/assets/' . $path;
        $type = self::TYPES[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? null;
        $real = realpath($file);
        $root = realpath($base);

        if ($type === null || str_contains($path, '..') || $real === false || $root === false || !str_starts_with($real, $root . '/') || !is_file($real) || !$this->themes->has($id)) {
            throw new NotFoundHttpException();
        }

        $response = new BinaryFileResponse($real, 200, ['Content-Type' => $type, 'X-Content-Type-Options' => 'nosniff'], true, null, true, true);
        $response->setPublic();
        $response->setMaxAge(3600);
        if ($type === 'image/svg+xml') {
            $response->headers->set('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox");
        }
        $response->isNotModified($request);

        return $response;
    }
}
