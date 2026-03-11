<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CacheEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CacheEntry>
 */
class CacheEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CacheEntry::class);
    }

    public function get(string $key): ?string
    {
        $entry = $this->find($key);
        if ($entry === null || $entry->isExpired()) {
            return null;
        }

        return $entry->getCacheValue();
    }

    public function purgeExpired(): int
    {
        return $this->createQueryBuilder('c')
            ->delete()
            ->where('c.expiresAt IS NOT NULL AND c.expiresAt < :now')
            ->setParameter('now', new \DateTime())
            ->getQuery()
            ->execute();
    }
}
