<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CacheEntryRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CacheEntryRepository::class)]
#[ORM\Table(name: 'cache')]
#[ORM\Index(name: 'idx_expires', columns: ['expires_at'])]
class CacheEntry
{
    #[ORM\Id]
    #[ORM\Column(name: 'cache_key', length: 255)]
    private string $cacheKey;

    #[ORM\Column(name: 'cache_value', type: 'text', nullable: true)]
    private ?string $cacheValue = null;

    #[ORM\Column(name: 'expires_at', type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $expiresAt = null;

    #[ORM\Column(name: 'created_at', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getCacheKey(): string
    {
        return $this->cacheKey;
    }

    public function setCacheKey(string $cacheKey): static
    {
        $this->cacheKey = $cacheKey;

        return $this;
    }

    public function getCacheValue(): ?string
    {
        return $this->cacheValue;
    }

    public function setCacheValue(?string $cacheValue): static
    {
        $this->cacheValue = $cacheValue;

        return $this;
    }

    public function getExpiresAt(): ?\DateTimeInterface
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeInterface $expiresAt): static
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function isExpired(): bool
    {
        return $this->expiresAt !== null && $this->expiresAt < new \DateTime();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
