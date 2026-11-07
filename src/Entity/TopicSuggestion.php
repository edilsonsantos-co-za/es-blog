<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A topic a reader would like to see written up, sent from the search page (/search#suggest).
 * The email is optional and only used to say when the post is out.
 */
#[ORM\Entity]
#[ORM\Table(name: 'topic_suggestions')]
#[ORM\Index(name: 'idx_topic_suggestions_created', columns: ['created_at'])]
class TopicSuggestion
{
    public const TOPIC_MAX = 160;
    public const DETAILS_MAX = 2000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
    public ?int $id = null;

    #[ORM\Column(length: self::TOPIC_MAX)]
    public string $topic;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $details = null;

    #[ORM\Column(length: 254, nullable: true)]
    public ?string $email = null;

    #[ORM\Column(type: 'datetime_immutable')]
    public \DateTimeImmutable $createdAt;

    /** When the owner marked it done (written, or decided against). */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $handledAt = null;

    public function __construct(string $topic, ?string $details, ?string $email)
    {
        $this->topic = $topic;
        $this->details = $details;
        $this->email = $email;
        $this->createdAt = new \DateTimeImmutable();
    }
}
