<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Tag;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Tag>
 */
class TagRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tag::class);
    }

    public function findBySlug(string $slug): ?Tag
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /** @return Tag[] */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('t')
            ->orderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOrCreate(string $slug, string $name): Tag
    {
        $tag = $this->findBySlug($slug);
        if ($tag === null) {
            $tag = new Tag();
            $tag->setSlug($slug);
            $tag->setName($name);
            $this->getEntityManager()->persist($tag);
        }

        return $tag;
    }

    /** @return array<array{tag: Tag, postCount: int}> */
    public function findAllWithPostCount(): array
    {
        return $this->createQueryBuilder('t')
            ->select('t, COUNT(p.id) as postCount')
            ->leftJoin('t.posts', 'p', 'WITH', 'p.status = :status')
            ->setParameter('status', 'published')
            ->groupBy('t.id')
            ->orderBy('postCount', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
