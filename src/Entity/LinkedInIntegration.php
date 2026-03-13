<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\LinkedInIntegrationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: LinkedInIntegrationRepository::class)]
#[ORM\Table(name: 'linkedin_integration')]
#[ORM\HasLifecycleCallbacks]
class LinkedInIntegration
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /**
     * LinkedIn API user identifier (e.g. "urn:li:person:abc123").
     * Stored as `linkedin_user_id` — separate from the app user FK below.
     */
    #[ORM\Column(name: 'linkedin_user_id', length: 255, unique: true)]
    private string $linkedInUserId;

    #[ORM\Column(name: 'access_token', type: 'text', nullable: true)]
    private ?string $accessToken = null;

    #[ORM\Column(name: 'token_expires_at', type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $tokenExpiresAt = null;

    #[ORM\Column(name: 'user_urn', length: 255, nullable: true)]
    private ?string $userUrn = null;

    #[ORM\Column(name: 'created_at', type: 'datetime', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeInterface $updatedAt;

    #[ORM\OneToOne(targetEntity: User::class, inversedBy: 'linkedInIntegration')]
    #[ORM\JoinColumn(name: 'app_user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    #[ORM\Column(name: 'app_user_id', type: 'integer', options: ['unsigned' => true])]
    private User $user;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLinkedInUserId(): string
    {
        return $this->linkedInUserId;
    }

    public function setLinkedInUserId(string $linkedInUserId): static
    {
        $this->linkedInUserId = $linkedInUserId;

        return $this;
    }

    public function getAccessToken(): ?string
    {
        return $this->accessToken;
    }

    public function setAccessToken(?string $accessToken): static
    {
        $this->accessToken = $accessToken;

        return $this;
    }

    public function getTokenExpiresAt(): ?\DateTimeInterface
    {
        return $this->tokenExpiresAt;
    }

    public function setTokenExpiresAt(?\DateTimeInterface $tokenExpiresAt): static
    {
        $this->tokenExpiresAt = $tokenExpiresAt;

        return $this;
    }

    public function isTokenExpired(): bool
    {
        return $this->tokenExpiresAt !== null && $this->tokenExpiresAt < new \DateTime();
    }

    public function getUserUrn(): ?string
    {
        return $this->userUrn;
    }

    public function setUserUrn(?string $userUrn): static
    {
        $this->userUrn = $userUrn;

        return $this;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeInterface
    {
        return $this->updatedAt;
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTime();
    }
}
