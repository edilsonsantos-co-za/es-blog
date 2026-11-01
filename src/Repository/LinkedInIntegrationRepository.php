<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LinkedInIntegration;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LinkedInIntegration>
 */
class LinkedInIntegrationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LinkedInIntegration::class);
    }

    public function findByLinkedInUserId(string $linkedInUserId): ?LinkedInIntegration
    {
        return $this->findOneBy(['linkedInUserId' => $linkedInUserId]);
    }

    public function findForUser(User $user): ?LinkedInIntegration
    {
        return $this->findOneBy(['user' => $user]);
    }
}
