<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AutomationRunRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Log line for one automation run (scheduled or started from the admin).
 */
#[ORM\Entity(repositoryClass: AutomationRunRepository::class)]
#[ORM\Table(name: 'automation_runs')]
class AutomationRun
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
    private ?int $id = null;

    /** "schedule", "admin" or "cli". */
    #[ORM\Column(name: 'trigger_source', length: 16)]
    private string $trigger;

    #[ORM\Column]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $feedsRead = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $itemsAdded = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $postsCreated = 0;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $log = [];

    #[ORM\Column(options: ['default' => true])]
    private bool $success = true;

    public function __construct(string $trigger)
    {
        $this->trigger = $trigger;
        $this->startedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getTrigger(): string { return $this->trigger; }

    public function getStartedAt(): \DateTimeImmutable { return $this->startedAt; }

    public function getFinishedAt(): ?\DateTimeImmutable { return $this->finishedAt; }

    public function getFeedsRead(): int { return $this->feedsRead; }

    public function getItemsAdded(): int { return $this->itemsAdded; }

    public function getPostsCreated(): int { return $this->postsCreated; }

    /** @return list<string> */
    public function getLog(): array { return $this->log; }

    public function isSuccess(): bool { return $this->success; }

    public function addFeedRead(int $newItems): void
    {
        ++$this->feedsRead;
        $this->itemsAdded += $newItems;
    }

    public function addPostCreated(): void { ++$this->postsCreated; }

    public function addLog(string $line, bool $isError = false): void
    {
        $this->log[] = $line;
        if ($isError) {
            $this->success = false;
        }
    }

    public function finish(): void { $this->finishedAt = new \DateTimeImmutable(); }
}
