<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\NewsletterSubscriber;
use App\Entity\Post;
use App\Enum\PostStatus;
use App\Enum\PostType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Numbers for the "my world of blogging" bento on the landing page: what's out, what's in the works,
 * what people read, how often posts ship and who reads them. Only public counts; never draft titles.
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
            'most_read'      => $this->published()->select('p')->andWhere('p.views > 0')
                ->orderBy('p.views', 'DESC')->setMaxResults(3)->getQuery()->getResult(),
            'rhythm'         => $this->rhythm(),
            'categories'     => $this->categories(),
            'readers'        => $this->readers(),
        ];
    }

    /** Posts per month for the last 12 months, oldest first, empty months included. @return list<array{label: string, month: string, count: int}> */
    private function rhythm(): array
    {
        $since = (new \DateTimeImmutable('first day of this month midnight'))->modify('-11 months');
        // Bucket in PHP: DQL has no YEAR()/MONTH() here, and a year of dates is tiny.
        $dates = $this->published()->select('p.publishedAt AS d')
            ->andWhere('p.publishedAt >= :since')->setParameter('since', $since)
            ->getQuery()->getSingleColumnResult();

        $counts = [];
        foreach ($dates as $date) {
            $key = ($date instanceof \DateTimeInterface ? $date : new \DateTimeImmutable((string) $date))->format('Y-m');
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        $months = [];
        for ($i = 0; $i < 12; ++$i) {
            $month = $since->modify("+$i months");
            $months[] = ['label' => $month->format('M'), 'month' => $month->format('F Y'), 'count' => $counts[$month->format('Y-m')] ?? 0];
        }

        return $months;
    }

    /** @return list<array{title: string, slug: string, count: int}> */
    private function categories(): array
    {
        $rows = $this->published()
            ->select('c.title AS title, c.slug AS slug, COUNT(p.id) AS n')
            ->join('p.category', 'c')
            ->groupBy('c.id, c.title, c.slug')->orderBy('n', 'DESC')->setMaxResults(5)
            ->getQuery()->getArrayResult();

        return array_map(static fn (array $r): array => ['title' => $r['title'], 'slug' => $r['slug'], 'count' => (int) $r['n']], $rows);
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
