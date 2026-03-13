<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Category;
use App\Entity\Post;
use App\Entity\Tag;
use App\Entity\User;
use App\Enum\PostStatus;
use App\Enum\PostType;
use App\Message\SyncLinkedInPostMessage;
use App\Repository\PostRepository;
use App\Repository\TagRepository;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\Pagination\PaginationInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Encapsulates all post business logic: creation, update, deletion, listing, search.
 *
 * Controllers must not touch the EntityManager directly for post operations —
 * all mutations go through this service to ensure consistent slug generation,
 * tag resolution, view counting, and scheduled-post handling.
 */
final class PostService
{
    public function __construct(
        private readonly PostRepository $postRepo,
        private readonly TagRepository $tagRepo,
        private readonly SlugService $slugService,
        private readonly EntityManagerInterface $em,
        private readonly PaginatorInterface $paginator,
        private readonly MessageBusInterface $bus,
    ) {}

    // -------------------------------------------------------------------------
    // Reads
    // -------------------------------------------------------------------------

    public function findBySlug(string $slug): ?Post
    {
        return $this->postRepo->findBySlug($slug);
    }

    public function findPublishedBySlug(string $slug): ?Post
    {
        return $this->postRepo->findPublishedBySlug($slug);
    }

    /**
     * Paginated list of published posts of a given type.
     *
     * @return PaginationInterface<int, Post>
     */
    public function paginate(
        int $page = 1,
        int $perPage = 10,
        PostType $type = PostType::Post,
    ): PaginationInterface {
        $qb = $this->postRepo->createPublishedQueryBuilder($type);

        return $this->paginator->paginate($qb, $page, $perPage);
    }

    /**
     * @return PaginationInterface<int, Post>
     */
    public function paginateByCategory(
        Category $category,
        int $page = 1,
        int $perPage = 10,
    ): PaginationInterface {
        $qb = $this->postRepo->createPublishedQueryBuilder()
            ->andWhere('p.category = :cat')
            ->setParameter('cat', $category);

        return $this->paginator->paginate($qb, $page, $perPage);
    }

    /**
     * @return PaginationInterface<int, Post>
     */
    public function paginateByTag(
        Tag $tag,
        int $page = 1,
        int $perPage = 10,
    ): PaginationInterface {
        $qb = $this->postRepo->createPublishedQueryBuilder()
            ->join('p.tags', 't')
            ->andWhere('t.id = :tagId')
            ->setParameter('tagId', $tag->getId());

        return $this->paginator->paginate($qb, $page, $perPage);
    }

    /**
     * @return PaginationInterface<int, Post>
     */
    public function paginateByAuthor(
        User $author,
        int $page = 1,
        int $perPage = 10,
        bool $publishedOnly = true,
    ): PaginationInterface {
        $qb = $this->postRepo->createQueryBuilder('p')
            ->where('p.author = :author')
            ->setParameter('author', $author)
            ->orderBy('p.createdAt', 'DESC');

        if ($publishedOnly) {
            $qb->andWhere('p.status = :status')
               ->setParameter('status', PostStatus::Published);
        }

        return $this->paginator->paginate($qb, $page, $perPage);
    }

    /**
     * Paginated archive by year/month.
     *
     * @return PaginationInterface<int, Post>
     */
    public function paginateArchive(int $year, ?int $month = null, int $page = 1, int $perPage = 10): PaginationInterface
    {
        $qb = $this->postRepo->createPublishedQueryBuilder();

        if ($month !== null) {
            $start = new \DateTime(sprintf('%04d-%02d-01', $year, $month));
            $end   = (clone $start)->modify('last day of this month')->setTime(23, 59, 59);
            $qb->andWhere('p.publishedAt BETWEEN :start AND :end')
               ->setParameter('start', $start)
               ->setParameter('end', $end);
        } else {
            $qb->andWhere('YEAR(p.publishedAt) = :year')
               ->setParameter('year', $year);
        }

        return $this->paginator->paginate($qb, $page, $perPage);
    }

    /**
     * @return PaginationInterface<int, Post>
     */
    public function search(string $query, int $page = 1, int $perPage = 10): PaginationInterface
    {
        $term = '%' . $query . '%';
        $qb = $this->postRepo->createPublishedQueryBuilder()
            ->andWhere('p.title LIKE :term OR p.body LIKE :term OR p.description LIKE :term')
            ->setParameter('term', $term);

        return $this->paginator->paginate($qb, $page, $perPage);
    }

    /** @return Post[] */
    public function findLatest(int $limit = 5, ?Post $exclude = null): array
    {
        $qb = $this->postRepo->createPublishedQueryBuilder()
            ->setMaxResults($limit);

        if ($exclude !== null) {
            $qb->andWhere('p.id != :exclude')->setParameter('exclude', $exclude->getId());
        }

        return $qb->getQuery()->getResult();
    }

    /** @return array<string, mixed> */
    public function getArchiveData(): array
    {
        return $this->postRepo->getArchiveData();
    }

    public function countByStatus(PostStatus $status): int
    {
        return $this->postRepo->countByStatus($status);
    }

    // -------------------------------------------------------------------------
    // Admin paginated queries (includes drafts/scheduled)
    // -------------------------------------------------------------------------

    /**
     * @return PaginationInterface<int, Post>
     */
    public function paginateAdmin(
        int $page = 1,
        int $perPage = 20,
        ?PostStatus $status = null,
        ?User $author = null,
    ): PaginationInterface {
        $qb = $this->postRepo->createQueryBuilder('p')
            ->orderBy('p.updatedAt', 'DESC');

        if ($status !== null) {
            $qb->andWhere('p.status = :status')->setParameter('status', $status);
        }

        if ($author !== null) {
            $qb->andWhere('p.author = :author')->setParameter('author', $author);
        }

        return $this->paginator->paginate($qb, $page, $perPage);
    }

    // -------------------------------------------------------------------------
    // Writes
    // -------------------------------------------------------------------------

    /**
     * Create a new post, auto-generating a unique slug if one is not provided.
     *
     * @param array<string, mixed> $data
     */
    public function create(array $data, User $author): Post
    {
        $post = new Post();
        $post->setAuthor($author);

        $this->hydrate($post, $data);

        if (empty($post->getSlug())) {
            $slug = $this->slugService->generatePostSlug($post->getTitle(), $post->getPublishedAt() ?? new \DateTime());
            $slug = $this->slugService->makeUnique($slug, fn (string $s) => $this->postRepo->findBySlug($s) !== null);
            $post->setSlug($slug);
        }

        $this->em->persist($post);
        $this->em->flush();

        if ($post->getStatus() === PostStatus::Published) {
            $this->bus->dispatch(new SyncLinkedInPostMessage($post->getId()));
        }

        return $post;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(Post $post, array $data): Post
    {
        $this->hydrate($post, $data);
        $this->em->flush();

        if ($post->getStatus() === PostStatus::Published) {
            $this->bus->dispatch(new SyncLinkedInPostMessage($post->getId()));
        }

        return $post;
    }

    public function delete(Post $post): void
    {
        $this->em->remove($post);
        $this->em->flush();
    }

    public function incrementViews(Post $post): void
    {
        $post->incrementViews();
        $this->em->flush();
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Apply a data array to a Post entity.
     *
     * @param array<string, mixed> $data
     */
    private function hydrate(Post $post, array $data): void
    {
        if (isset($data['title'])) {
            $post->setTitle((string) $data['title']);
        }
        if (isset($data['body'])) {
            $post->setBody((string) $data['body']);
        }
        if (array_key_exists('description', $data)) {
            $post->setDescription($data['description'] !== '' ? (string) $data['description'] : null);
        }
        if (isset($data['slug']) && $data['slug'] !== '') {
            $post->setSlug((string) $data['slug']);
        }
        if (isset($data['status'])) {
            $post->setStatus($data['status'] instanceof PostStatus ? $data['status'] : PostStatus::from($data['status']));
        }
        if (isset($data['type'])) {
            $post->setType($data['type'] instanceof PostType ? $data['type'] : PostType::from($data['type']));
        }
        if (isset($data['category'])) {
            $post->setCategory($data['category'] instanceof Category ? $data['category'] : null);
        }
        if (array_key_exists('published_at', $data)) {
            $post->setPublishedAt($data['published_at'] instanceof \DateTimeInterface ? $data['published_at'] : null);
        }
        foreach (['image', 'video', 'audio', 'link', 'quote'] as $field) {
            if (array_key_exists($field, $data)) {
                $setter = 'set' . ucfirst($field);
                $post->$setter($data[$field] !== '' ? (string) $data[$field] : null);
            }
        }

        // Tags: accept Tag[] or comma-separated string
        if (isset($data['tags'])) {
            $this->syncTags($post, $data['tags']);
        }
    }

    /**
     * @param Tag[]|string $tags  Either Tag objects or "php, symfony, doctrine"
     */
    private function syncTags(Post $post, array|string $tags): void
    {
        // Remove all existing tags
        foreach ($post->getTags() as $tag) {
            $post->removeTag($tag);
        }

        if (is_string($tags)) {
            $tags = array_filter(array_map('trim', explode(',', $tags)));
            $tags = array_map(
                fn (string $name) => $this->tagRepo->findOrCreate(
                    $this->slugService->generate($name),
                    $name,
                ),
                $tags,
            );
        }

        foreach ($tags as $tag) {
            $post->addTag($tag);
        }
    }
}
