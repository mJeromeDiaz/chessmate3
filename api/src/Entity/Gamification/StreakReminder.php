<?php

declare(strict_types=1);

namespace App\Entity\Gamification;

use App\Entity\User;
use App\Repository\Gamification\StreakReminderRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * The "streak in danger" reminder of a user (docs/NOTIFICATIONS.md, § 5): its settings and when it
 * is next due. No row: the defaults (on, 20 h, push only). Written in plain SQL by
 * {@see \App\Gamification\Streak\StreakReminders}.
 */
#[ORM\Entity(repositoryClass: StreakReminderRepository::class, readOnly: true)]
#[ORM\Table(name: 'gamification_streak_reminder')]
#[ORM\UniqueConstraint(name: 'uniq_gamification_streak_reminder_user', columns: ['user_id'])]
#[ORM\Index(name: 'idx_gamification_streak_reminder_due', columns: ['next_due_at'])]
class StreakReminder
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\Column]
        private bool $enabled = true,
        /** Local hour it is sent at. */
        #[ORM\Column(type: 'smallint')]
        private int $hour = 20,
        /** Also by email (push only by default). */
        #[ORM\Column]
        private bool $email = false,
        /** When it is next due (UTC): the hour of the day after the last active day; null once sent. */
        #[ORM\Column(nullable: true)]
        private ?\DateTimeImmutable $nextDueAt = null,
    ) {
        $this->id = Uuid::v7();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getHour(): int
    {
        return $this->hour;
    }

    public function isEmail(): bool
    {
        return $this->email;
    }

    public function getNextDueAt(): ?\DateTimeImmutable
    {
        return $this->nextDueAt;
    }
}
