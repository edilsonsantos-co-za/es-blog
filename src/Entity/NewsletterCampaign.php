<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'newsletter_campaigns')]
#[ORM\UniqueConstraint(name: 'uniq_newsletter_post', columns: ['post_id'])]
class NewsletterCampaign
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Post::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    public ?Post $post = null;

    #[ORM\Column(length: 16)]
    public string $kind = 'post';

    #[ORM\Column(length: 255)]
    public string $subject = '';

    #[ORM\Column(type: 'text')]
    public string $body = '';

    #[ORM\Column(type: 'datetime_immutable')]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $queuedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }
}
