<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AutomationRun;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AutomationRun>
 */
class AutomationRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AutomationRun::class);
    }

    /** @return AutomationRun[] */
    public function findLatest(int $limit = 10): array
    {
        return $this->findBy([], ['startedAt' => 'DESC'], $limit);
    }
}
