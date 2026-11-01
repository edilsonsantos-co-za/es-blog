<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'newsletter_subscribers')]
#[ORM\UniqueConstraint(name: 'uniq_newsletter_email', columns: ['email'])]
#[ORM\UniqueConstraint(name: 'uniq_newsletter_unsubscribe', columns: ['unsubscribe_hash'])]
class NewsletterSubscriber
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
    public ?int $id = null;

    #[ORM\Column(length: 254)]
    public string $email;

    #[ORM\Column]
    public bool $posts = false;

    #[ORM\Column]
    public bool $products = false;

    #[ORM\Column(length: 64, nullable: true)]
    public ?string $confirmationHash = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $confirmationExpiresAt = null;

    #[ORM\Column(length: 64)]
    public string $unsubscribeHash;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $confirmedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $consentUpdatedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $unsubscribedAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    public \DateTimeImmutable $requestedAt;

    #[ORM\Column(length: 32)]
    public string $consentVersion = '2026-11-02';

    public function __construct(string $email)
    {
        $this->email = $email;
        $this->requestedAt = new \DateTimeImmutable();
    }

    public function eligible(string $kind): bool
    {
        return $this->confirmedAt !== null && $this->unsubscribedAt === null
            && ($kind === 'post' ? $this->posts : ($kind === 'product' && $this->products));
    }

    public function confirm(string $token, ?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable();
        if ($this->confirmationHash === null || $this->confirmationExpiresAt === null
            || $now >= $this->confirmationExpiresAt
            || !hash_equals($this->confirmationHash, hash('sha256', $token))) {
            return false;
        }
        $this->confirmedAt = $now;
        $this->consentUpdatedAt = $now;
        $this->unsubscribedAt = null;
        $this->confirmationHash = null;
        $this->confirmationExpiresAt = null;

        return true;
    }

    public function unsubscribe(): void
    {
        $this->unsubscribedAt = new \DateTimeImmutable();
        $this->consentUpdatedAt = $this->unsubscribedAt;
        $this->posts = $this->products = false;
        $this->confirmationHash = null;
        $this->confirmationExpiresAt = null;
    }
}
