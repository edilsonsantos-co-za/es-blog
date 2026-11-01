<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Post;
use App\Entity\XPostShare;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<XPostShare> */
class XPostShareRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, XPostShare::class);
    }

    public function findForPost(Post $post): ?XPostShare
    {
        return $this->findOneBy(['post' => $post]);
    }
}
