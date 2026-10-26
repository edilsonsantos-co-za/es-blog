<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FeedSourceRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * An RSS or Atom feed the automation reads, usually imported from a Feeder.co OPML export.
 */
#[ORM\Entity(repositoryClass: FeedSourceRepository::class)]
#[ORM\Table(name: 'feed_sources')]
class FeedSource
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 768, unique: true)]
    private string $url;

    /** Folder name from the OPML export, kept for grouping in the admin. */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $folder = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $enabled = true;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastFetchedAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $name, string $url, ?string $folder = null)
    {
        $this->name = $name;
        $this->url = $url;
        $this->folder = $folder;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getName(): string { return $this->name; }

    public function setName(string $name): static { $this->name = $name; return $this; }

    public function getUrl(): string { return $this->url; }

    public function getFolder(): ?string { return $this->folder; }

    public function setFolder(?string $folder): static { $this->folder = $folder; return $this; }

    public function isEnabled(): bool { return $this->enabled; }

    public function setEnabled(bool $enabled): static { $this->enabled = $enabled; return $this; }

    public function getLastFetchedAt(): ?\DateTimeImmutable { return $this->lastFetchedAt; }

    public function getLastError(): ?string { return $this->lastError; }

    public function markFetched(?string $error = null): static
    {
        $this->lastFetchedAt = new \DateTimeImmutable();
        $this->lastError = $error;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
