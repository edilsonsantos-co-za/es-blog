<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FeedItemRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One article seen in a feed. Status moves new → drafted (a post was written from it) or skipped.
 */
#[ORM\Entity(repositoryClass: FeedItemRepository::class)]
#[ORM\Table(name: 'feed_items')]
#[ORM\Index(name: 'idx_feed_items_status', columns: ['status', 'score'])]
class FeedItem
{
    public const STATUS_NEW = 'new';
    public const STATUS_DRAFTED = 'drafted';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: FeedSource::class)]
    #[ORM\JoinColumn(name: 'source_id', nullable: false, onDelete: 'CASCADE')]
    private FeedSource $source;

    /** sha1 of the normalised link, so the same article from two feeds is stored once. */
    #[ORM\Column(length: 40, unique: true)]
    private string $fingerprint;

    #[ORM\Column(length: 1024)]
    private string $link;

    #[ORM\Column(length: 500)]
    private string $title;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $summary = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $score = 0;

    #[ORM\Column(length: 16, options: ['default' => self::STATUS_NEW])]
    private string $status = self::STATUS_NEW;

    #[ORM\ManyToOne(targetEntity: Post::class)]
    #[ORM\JoinColumn(name: 'post_id', nullable: true, onDelete: 'SET NULL')]
    private ?Post $post = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $error = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(FeedSource $source, string $link, string $title)
    {
        $this->source = $source;
        $this->link = $link;
        $this->title = $title;
        $this->fingerprint = self::fingerprintFor($link);
        $this->createdAt = new \DateTimeImmutable();
    }

    public static function fingerprintFor(string $link): string
    {
        $parts = parse_url(trim($link)) ?: [];
        $host = strtolower(preg_replace('/^www\./', '', $parts['host'] ?? ''));
        $path = rtrim($parts['path'] ?? '', '/');

        return sha1($host . $path);
    }

    public function getId(): ?int { return $this->id; }

    public function getSource(): FeedSource { return $this->source; }

    public function getFingerprint(): string { return $this->fingerprint; }

    public function getLink(): string { return $this->link; }

    public function getTitle(): string { return $this->title; }

    public function getSummary(): ?string { return $this->summary; }

    public function setSummary(?string $summary): static { $this->summary = $summary; return $this; }

    public function getPublishedAt(): ?\DateTimeImmutable { return $this->publishedAt; }

    public function setPublishedAt(?\DateTimeImmutable $publishedAt): static { $this->publishedAt = $publishedAt; return $this; }

    public function getScore(): int { return $this->score; }

    public function setScore(int $score): static { $this->score = $score; return $this; }

    public function getStatus(): string { return $this->status; }

    public function setStatus(string $status): static { $this->status = $status; return $this; }

    public function getPost(): ?Post { return $this->post; }

    public function setPost(?Post $post): static { $this->post = $post; return $this; }

    public function getError(): ?string { return $this->error; }

    public function setError(?string $error): static { $this->error = $error; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
