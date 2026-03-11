<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Post;
use App\Enum\PostStatus;
use App\Enum\PostType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Post>
 */
class PostRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Post::class);
    }

    public function findBySlug(string $slug): ?Post
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    public function findPublishedBySlug(string $slug): ?Post
    {
        return $this->createQueryBuilder('p')
            ->where('p.slug = :slug')
            ->andWhere('p.status = :status')
            ->andWhere('p.publishedAt IS NULL OR p.publishedAt <= :now')
            ->setParameter('slug', $slug)
            ->setParameter('status', PostStatus::Published)
            ->setParameter('now', new \DateTime())
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function createPublishedQueryBuilder(PostType $type = PostType::Post): QueryBuilder
    {
        return $this->createQueryBuilder('p')
            ->where('p.status = :status')
            ->andWhere('p.type = :type')
            ->andWhere('p.publishedAt IS NULL OR p.publishedAt <= :now')
            ->setParameter('status', PostStatus::Published)
            ->setParameter('type', $type)
            ->setParameter('now', new \DateTime())
            ->orderBy('p.publishedAt', 'DESC');
    }

    /** @return Post[] */
    public function findLatestPosts(int $limit = 10): array
    {
        return $this->createPublishedQueryBuilder()
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** @return Post[] */
    public function findByCategory(int $categoryId): array
    {
        return $this->createPublishedQueryBuilder()
            ->andWhere('p.category = :categoryId')
            ->setParameter('categoryId', $categoryId)
            ->getQuery()
            ->getResult();
    }

    /** @return Post[] */
    public function findByTag(int $tagId): array
    {
        return $this->createPublishedQueryBuilder()
            ->join('p.tags', 't')
            ->andWhere('t.id = :tagId')
            ->setParameter('tagId', $tagId)
            ->getQuery()
            ->getResult();
    }

    /** @return Post[] */
    public function findByAuthor(int $authorId, bool $publishedOnly = true): array
    {
        $qb = $this->createQueryBuilder('p')
            ->where('p.author = :authorId')
            ->setParameter('authorId', $authorId)
            ->orderBy('p.createdAt', 'DESC');

        if ($publishedOnly) {
            $qb->andWhere('p.status = :status')
               ->setParameter('status', PostStatus::Published);
        }

        return $qb->getQuery()->getResult();
    }

    /** Full-text search using LIKE (MySQL FULLTEXT via native query if needed later). */
    /** @return Post[] */
    public function search(string $query, int $limit = 20): array
    {
        $term = '%' . $query . '%';

        return $this->createPublishedQueryBuilder()
            ->andWhere('p.title LIKE :term OR p.body LIKE :term OR p.description LIKE :term')
            ->setParameter('term', $term)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countByStatus(PostStatus $status): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.status = :status')
            ->setParameter('status', $status)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return array<string, mixed> Archive data: year => [month => count] */
    public function getArchiveData(): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('YEAR(p.publishedAt) as year, MONTH(p.publishedAt) as month, COUNT(p.id) as postCount')
            ->where('p.status = :status')
            ->andWhere('p.type = :type')
            ->andWhere('p.publishedAt IS NOT NULL')
            ->setParameter('status', PostStatus::Published)
            ->setParameter('type', PostType::Post)
            ->groupBy('year, month')
            ->orderBy('year', 'DESC')
            ->addOrderBy('month', 'DESC')
            ->getQuery()
            ->getResult();

        $archive = [];
        foreach ($rows as $row) {
            $archive[$row['year']][$row['month']] = (int) $row['postCount'];
        }

        return $archive;
    }
}
