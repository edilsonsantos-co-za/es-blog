<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\XIntegration;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<XIntegration> */
class XIntegrationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, XIntegration::class);
    }

    public function findForUser(User $user): ?XIntegration
    {
        return $this->findOneBy(['user' => $user]);
    }

    public function findByXUserId(string $xUserId): ?XIntegration
    {
        return $this->findOneBy(['xUserId' => $xUserId]);
    }
}
