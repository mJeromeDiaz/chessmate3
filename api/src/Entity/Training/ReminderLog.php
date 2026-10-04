<?php

declare(strict_types=1);

namespace App\Entity\Training;

use App\Repository\Training\ReminderLogRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A reminder sent for one occurrence of a saved session (docs/TRAINING.md): the unique index on
 * (plan, occurrence) makes sending idempotent, whatever overlaps between cron runs. Written with
 * INSERT IGNORE by {@see \App\Repository\Training\ReminderLogRepository::claim()}.
 */
#[ORM\Entity(repositoryClass: ReminderLogRepository::class)]
#[ORM\Table(name: 'training_reminder_log')]
#[ORM\UniqueConstraint(name: 'uniq_training_reminder_log_plan_occurrence', columns: ['plan_id', 'occurs_at'])]
#[ORM\Index(name: 'idx_training_reminder_log_plan', columns: ['plan_id'])]
class ReminderLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Plan::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Plan $plan;

    #[ORM\Column]
    private \DateTimeImmutable $occursAt;

    #[ORM\Column]
    private \DateTimeImmutable $sentAt;

    public function __construct(Plan $plan, \DateTimeImmutable $occursAt, \DateTimeImmutable $sentAt)
    {
        $this->plan = $plan;
        $this->occursAt = $occursAt;
        $this->sentAt = $sentAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPlan(): Plan
    {
        return $this->plan;
    }

    public function getOccursAt(): \DateTimeImmutable
    {
        return $this->occursAt;
    }

    public function getSentAt(): \DateTimeImmutable
    {
        return $this->sentAt;
    }
}
