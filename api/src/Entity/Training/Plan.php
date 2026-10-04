<?php

declare(strict_types=1);

namespace App\Entity\Training;

use App\Entity\User;
use App\Enum\Training\Module;
use App\Enum\Training\Repetition;
use App\Repository\Training\PlanRepository;
use App\Training\Plan\Schedule;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A saved training session (docs/TRAINING.md): its program and its settings (repetition, public
 * flag, reminder, calendar). Launching it creates a played {@see Session} with a frozen copy of the
 * program: changing or deleting the plan only changes the future.
 *
 * @phpstan-type PlanStep array{module: string, minutes: int, notes: string, settings: array<string, mixed>}
 */
#[ORM\Entity(repositoryClass: PlanRepository::class)]
#[ORM\Table(name: 'training_session_plan')]
#[ORM\Index(name: 'idx_training_session_plan_user_updated', columns: ['user_id', 'updated_at'])]
#[ORM\Index(name: 'idx_training_session_plan_user', columns: ['user_id'])]
#[ORM\Index(name: 'idx_training_session_plan_reminder', columns: ['reminder_enabled'])]
class Plan
{
    public const MAX_PER_USER = 50;
    /** Reminder delays offered, in minutes before the session. */
    public const REMINDER_MINUTES = [10, 30, 60, 1440];
    public const REMINDER_CHANNELS = ['email', 'push'];

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 120)]
    private string $title = '';

    #[ORM\Column(length: 500)]
    private string $description = '';

    /** @var list<PlanStep> */
    #[ORM\Column(type: Types::JSON)]
    private array $steps = [];

    #[ORM\Column(length: 16, enumType: Repetition::class)]
    private Repetition $repetition = Repetition::OnDemand;

    /** Local time "HH:MM" (null on demand). */
    #[ORM\Column(length: 5, nullable: true)]
    private ?string $time = null;

    /** @var list<int> ISO days of the week (empty on demand) */
    #[ORM\Column(type: Types::JSON)]
    private array $weekdays = [];

    /** A flag only for now (a future community feature). */
    #[ORM\Column(options: ['default' => false])]
    private bool $public = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $reminderEnabled = false;

    /** @var list<string> email, push */
    #[ORM\Column(type: Types::JSON)]
    private array $reminderChannels = [];

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true, 'default' => 30])]
    private int $reminderMinutes = 30;

    /** Listed in the user's calendar feed. */
    #[ORM\Column(options: ['default' => false])]
    private bool $calendarEnabled = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $user, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * Replaces the program and the settings (already validated).
     *
     * @param list<array{module: Module, minutes: int, notes: string, settings: array<string, mixed>}> $steps
     * @param list<int>                                                                                 $weekdays
     * @param list<string>                                                                              $reminderChannels
     */
    public function update(
        string $title,
        string $description,
        array $steps,
        Repetition $repetition,
        ?string $time,
        array $weekdays,
        bool $public,
        bool $reminderEnabled,
        array $reminderChannels,
        int $reminderMinutes,
        bool $calendarEnabled,
        \DateTimeImmutable $now,
    ): void {
        $this->title = $title;
        $this->description = $description;
        $this->steps = array_map(static fn (array $step): array => [
            'module' => $step['module']->value,
            'minutes' => $step['minutes'],
            'notes' => $step['notes'],
            'settings' => $step['settings'],
        ], $steps);
        $onDemand = Repetition::OnDemand === $repetition;
        $this->repetition = $repetition;
        $this->time = $onDemand ? null : $time;
        $this->weekdays = $onDemand ? [] : $weekdays;
        $this->public = $public;
        // No time, nothing to remind of nor to put in a calendar.
        $this->reminderEnabled = !$onDemand && $reminderEnabled;
        $this->reminderChannels = $reminderChannels;
        $this->reminderMinutes = $reminderMinutes;
        $this->calendarEnabled = !$onDemand && $calendarEnabled;
        $this->updatedAt = $now;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * @return list<PlanStep>
     */
    public function getSteps(): array
    {
        return $this->steps;
    }

    /**
     * The program as a session takes it.
     *
     * @return list<array{module: Module, minutes: int, notes: string, settings: array<string, mixed>}>
     */
    public function sessionSteps(): array
    {
        return array_map(static fn (array $step): array => ['module' => Module::from($step['module'])] + $step, $this->steps);
    }

    public function getRepetition(): Repetition
    {
        return $this->repetition;
    }

    public function getTime(): ?string
    {
        return $this->time;
    }

    /**
     * @return list<int>
     */
    public function getWeekdays(): array
    {
        return $this->weekdays;
    }

    public function isPublic(): bool
    {
        return $this->public;
    }

    public function isReminderEnabled(): bool
    {
        return $this->reminderEnabled;
    }

    /**
     * @return list<string>
     */
    public function getReminderChannels(): array
    {
        return $this->reminderChannels;
    }

    public function getReminderMinutes(): int
    {
        return $this->reminderMinutes;
    }

    public function isCalendarEnabled(): bool
    {
        return $this->calendarEnabled;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function schedule(): Schedule
    {
        return new Schedule($this->repetition, $this->time ?? '00:00', $this->weekdays, $this->user->getDateTimeZone());
    }
}
