<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Generates and validates URL-safe slugs.
 * Wraps Symfony's AsciiSlugger with blog-specific rules.
 */
final class SlugService
{
    public function __construct(
        private readonly SluggerInterface $slugger,
    ) {}

    /**
     * Convert any string to a URL-safe slug.
     * e.g. "Hello World! 2024" → "hello-world-2024"
     */
    public function generate(string $title): string
    {
        return strtolower((string) $this->slugger->slug($title));
    }

    /**
     * Build a dated post slug: "2024/01/hello-world"
     * Mirrors the v1 format used in existing DB rows.
     */
    public function generatePostSlug(string $title, ?\DateTimeInterface $date = null): string
    {
        $date ??= new \DateTime();
        $base = $this->generate($title);

        return sprintf('%s/%s/%s', $date->format('Y'), $date->format('m'), $base);
    }

    /**
     * Ensure a slug is unique within a candidate set, appending -2, -3… as needed.
     *
     * @param callable(string): bool $existsCallback Returns true if the slug is already taken
     */
    public function makeUnique(string $slug, callable $existsCallback): string
    {
        if (!$existsCallback($slug)) {
            return $slug;
        }

        $counter = 2;
        do {
            $candidate = $slug . '-' . $counter;
            $counter++;
        } while ($existsCallback($candidate));

        return $candidate;
    }

    /**
     * Validate that a slug contains only lowercase letters, digits, hyphens, and forward slashes.
     */
    public function isValid(string $slug): bool
    {
        return (bool) preg_match('/^[a-z0-9\-\/]+$/', $slug);
    }
}
