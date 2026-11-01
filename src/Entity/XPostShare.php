<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\XPostShareRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: XPostShareRepository::class)]
#[ORM\Table(name: 'x_post_share')]
class XPostShare
{
    /** Claimed before calling X; a crash or DB failure after sending leaves it pending (needs reconciliation). */
    public const PENDING = 'pending';
    public const SENT = 'sent';
    /** X may or may not have created the post (timeout, 5xx, bad response). Never retried automatically. */
    public const UNKNOWN = 'unknown';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: Post::class)]
    #[ORM\JoinColumn(name: 'post_id', referencedColumnName: 'id', nullable: false, unique: true, onDelete: 'CASCADE')]
    private Post $post;

    #[ORM\ManyToOne(targetEntity: XIntegration::class)]
    #[ORM\JoinColumn(name: 'integration_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?XIntegration $integration = null;

    #[ORM\Column(length: 16)]
    private string $status = self::PENDING;

    /** sha256 of the exact reviewed text that was claimed for sending. */
    #[ORM\Column(name: 'text_hash', length: 64)]
    private string $textHash = '';

    #[ORM\Column(name: 'x_post_id', length: 64, unique: true, nullable: true)]
    private ?string $xPostId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $error = null;

    #[ORM\Column(name: 'shared_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $sharedAt;

    public function __construct()
    {
        $this->sharedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPost(): Post
    {
        return $this->post;
    }

    public function setPost(Post $post): static
    {
        $this->post = $post;

        return $this;
    }

    public function getIntegration(): ?XIntegration
    {
        return $this->integration;
    }

    public function setIntegration(?XIntegration $integration): static
    {
        $this->integration = $integration;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getTextHash(): string
    {
        return $this->textHash;
    }

    public function getXPostId(): ?string
    {
        return $this->xPostId;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getSharedAt(): \DateTimeImmutable
    {
        return $this->sharedAt;
    }
}
