<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\NewsletterSubscriber;
use App\Entity\Post;
use App\Enum\PostStatus;
use App\Enum\PostType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Numbers for the landing page's hero cards: what's out, what's in the works, what people read and
 * who reads them. Only public counts; never draft titles.
 */
final class BlogInsights
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    /** @return array<string, mixed> */
    public function summary(): array
    {
        $published = $this->published();

        $bodies = $published->select('p.body')->getQuery()->getSingleColumnResult();
        $readMinutes = array_sum(array_map(
            static fn (string $body): int => max(1, (int) round(str_word_count(strip_tags($body)) / 200)),
            $bodies,
        ));

        $lastPublished = $this->published()->select('MAX(p.publishedAt)')->getQuery()->getSingleScalarResult();
        $nextScheduled = $this->posts()->select('MIN(p.publishedAt)')
            ->andWhere('p.status = :s')->setParameter('s', PostStatus::Scheduled)
            ->andWhere('p.publishedAt > :now')->setParameter('now', new \DateTimeImmutable())
            ->getQuery()->getSingleScalarResult();

        return [
            'published'      => \count($bodies),
            'drafts'         => $this->countStatus(PostStatus::Draft),
            'scheduled'      => $this->countStatus(PostStatus::Scheduled),
            'next_scheduled' => $nextScheduled ? new \DateTimeImmutable((string) $nextScheduled) : null,
            'last_published' => $lastPublished ? new \DateTimeImmutable((string) $lastPublished) : null,
            'reads'          => (int) $this->published()->select('COALESCE(SUM(p.views), 0)')->getQuery()->getSingleScalarResult(),
            'read_minutes'   => $readMinutes,
            'readers'        => $this->readers(),
        ];
    }

    private function readers(): int
    {
        return (int) $this->em->createQueryBuilder()->select('COUNT(s.id)')->from(NewsletterSubscriber::class, 's')
            ->where('s.confirmedAt IS NOT NULL')->andWhere('s.unsubscribedAt IS NULL')->andWhere('s.posts = true')
            ->getQuery()->getSingleScalarResult();
    }

    private function countStatus(PostStatus $status): int
    {
        return (int) $this->posts()->select('COUNT(p.id)')
            ->andWhere('p.status = :s')->setParameter('s', $status)
            ->getQuery()->getSingleScalarResult();
    }

    private function posts(): \Doctrine\ORM\QueryBuilder
    {
        return $this->em->createQueryBuilder()->from(Post::class, 'p')
            ->where('p.type = :type')->setParameter('type', PostType::Post);
    }

    private function published(): \Doctrine\ORM\QueryBuilder
    {
        return $this->posts()
            ->andWhere('p.status = :published')->setParameter('published', PostStatus::Published)
            ->andWhere('p.publishedAt IS NOT NULL');
    }
}
