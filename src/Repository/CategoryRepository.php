<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Category;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Category>
 */
class CategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Category::class);
    }

    public function findBySlug(string $slug): ?Category
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /** @return Category[] */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('c')
            ->orderBy('c.title', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return array<array{category: Category, postCount: int}> */
    public function findAllWithPostCount(): array
    {
        return $this->createQueryBuilder('c')
            ->select('c, COUNT(p.id) as postCount')
            ->leftJoin('c.posts', 'p', 'WITH', 'p.status = :status')
            ->setParameter('status', 'published')
            ->groupBy('c.id')
            ->orderBy('c.title', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
