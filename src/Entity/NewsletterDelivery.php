<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'newsletter_deliveries')]
#[ORM\UniqueConstraint(name: 'uniq_newsletter_delivery', columns: ['campaign_id', 'subscriber_id'])]
#[ORM\Index(name: 'IDX_NEWSLETTER_SUBSCRIBER', columns: ['subscriber_id'])]
class NewsletterDelivery
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: NewsletterCampaign::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public NewsletterCampaign $campaign;

    #[ORM\ManyToOne(targetEntity: NewsletterSubscriber::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public NewsletterSubscriber $subscriber;

    #[ORM\Column(length: 16)]
    public string $status = 'pending';

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $sentAt = null;
}
