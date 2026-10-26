<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FeedItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FeedItem>
 */
class FeedItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FeedItem::class);
    }

    public function existsFingerprint(string $fingerprint): bool
    {
        return $this->count(['fingerprint' => $fingerprint]) > 0;
    }

    /**
     * Highest-scoring new items published after $since, best first.
     *
     * @return FeedItem[]
     */
    public function findCandidates(\DateTimeImmutable $since, int $minScore, int $limit): array
    {
        return $this->createQueryBuilder('i')
            ->where('i.status = :status')
            ->andWhere('i.score >= :minScore')
            ->andWhere('i.publishedAt IS NULL OR i.publishedAt >= :since')
            ->setParameter('status', FeedItem::STATUS_NEW)
            ->setParameter('minScore', $minScore)
            ->setParameter('since', $since)
            ->orderBy('i.score', 'DESC')
            ->addOrderBy('i.publishedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** @return FeedItem[] */
    public function findLatest(int $limit, ?string $status = null): array
    {
        $qb = $this->createQueryBuilder('i')
            ->leftJoin('i.source', 's')->addSelect('s')
            ->orderBy('i.createdAt', 'DESC')
            ->addOrderBy('i.score', 'DESC')
            ->setMaxResults($limit);

        if ($status !== null) {
            $qb->where('i.status = :status')->setParameter('status', $status);
        }

        return $qb->getQuery()->getResult();
    }

    /** @return array<string, int> status => count */
    public function countByStatus(): array
    {
        $rows = $this->createQueryBuilder('i')
            ->select('i.status AS status, COUNT(i.id) AS total')
            ->groupBy('i.status')
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'total', 'status');
    }
}
