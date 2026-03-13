<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Category;
use App\Repository\CategoryRepository;
use Doctrine\ORM\EntityManagerInterface;

final class CategoryService
{
    public function __construct(
        private readonly CategoryRepository $repo,
        private readonly SlugService $slugService,
        private readonly EntityManagerInterface $em,
    ) {}

    public function findBySlug(string $slug): ?Category
    {
        return $this->repo->findBySlug($slug);
    }

    /** @return Category[] */
    public function findAll(): array
    {
        return $this->repo->findAllOrdered();
    }

    /** @return array<array{category: Category, postCount: int}> */
    public function findAllWithCounts(): array
    {
        return $this->repo->findAllWithPostCount();
    }

    public function create(string $title, ?string $description = null, ?string $slug = null): Category
    {
        $slug ??= $this->slugService->generate($title);
        $slug   = $this->slugService->makeUnique($slug, fn (string $s) => $this->repo->findBySlug($s) !== null);

        $category = new Category();
        $category->setSlug($slug);
        $category->setTitle($title);
        $category->setDescription($description);

        $this->em->persist($category);
        $this->em->flush();

        return $category;
    }

    public function update(Category $category, string $title, ?string $description = null, ?string $slug = null): Category
    {
        $category->setTitle($title);
        $category->setDescription($description);

        if ($slug !== null && $slug !== $category->getSlug()) {
            $slug = $this->slugService->makeUnique(
                $slug,
                fn (string $s) => $this->repo->findBySlug($s) !== null && $s !== $category->getSlug(),
            );
            $category->setSlug($slug);
        }

        $this->em->flush();

        return $category;
    }

    public function delete(Category $category): void
    {
        $this->em->remove($category);
        $this->em->flush();
    }
}
