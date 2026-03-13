<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Tag;
use App\Repository\TagRepository;
use Doctrine\ORM\EntityManagerInterface;

final class TagService
{
    public function __construct(
        private readonly TagRepository $repo,
        private readonly SlugService $slugService,
        private readonly EntityManagerInterface $em,
    ) {}

    public function findBySlug(string $slug): ?Tag
    {
        return $this->repo->findBySlug($slug);
    }

    /** @return Tag[] */
    public function findAll(): array
    {
        return $this->repo->findAllOrdered();
    }

    /** @return array<array{tag: Tag, postCount: int}> */
    public function findAllWithCounts(): array
    {
        return $this->repo->findAllWithPostCount();
    }

    /**
     * Parse a comma-separated tag string and return resolved Tag entities.
     * Creates tags that don't exist yet.
     *
     * @return Tag[]
     */
    public function parseAndResolve(string $tagString): array
    {
        $names = array_filter(array_map('trim', explode(',', $tagString)));
        $tags  = [];

        foreach ($names as $name) {
            $tags[] = $this->repo->findOrCreate(
                $this->slugService->generate($name),
                $name,
            );
        }

        $this->em->flush();

        return $tags;
    }

    public function delete(Tag $tag): void
    {
        $this->em->remove($tag);
        $this->em->flush();
    }

    /**
     * Convert a collection of Tag entities to a comma-separated name string.
     *
     * @param iterable<Tag> $tags
     */
    public function toCommaSeparated(iterable $tags): string
    {
        $names = [];
        foreach ($tags as $tag) {
            $names[] = $tag->getName();
        }

        return implode(', ', $names);
    }
}
