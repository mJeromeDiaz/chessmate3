<?php

declare(strict_types=1);

namespace App\Entity\PuzzleStreak;

use App\Entity\Training\Run;
use App\Entity\User;
use App\Enum\PuzzleStreak\Ramp;
use App\Enum\PuzzleStreak\StreakStatus;
use App\Repository\PuzzleStreak\StreakRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A puzzle streak (docs/PUZZLE_STREAK.md): puzzles solved in a row in a run, from very easy to
 * harder, until a miss or the end of the run. A run of the streak page has one; a session step's
 * run has one after the other. $record: it beat the user's best of its ramp and topic.
 */
#[ORM\Entity(repositoryClass: StreakRepository::class)]
#[ORM\Table(name: 'puzzle_streak')]
#[ORM\Index(name: 'idx_puzzle_streak_run', columns: ['run_id', 'started_at'])]
#[ORM\Index(name: 'idx_puzzle_streak_user_topic', columns: ['user_id', 'ramp', 'topic', 'length'])]
class Streak
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Run::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Run $run;

    #[ORM\Column(length: 8, enumType: Ramp::class)]
    private Ramp $ramp;

    /** {@see \App\PuzzleStreak\Selection\Topic}: an opening's tag is case-sensitive. */
    #[ORM\Column(length: 80, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $topic;

    /** Puzzles solved in a row. */
    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true, 'default' => 0])]
    private int $length = 0;

    /** The rating of the hardest puzzle solved. */
    #[ORM\Column(type: Types::SMALLINT, nullable: true, options: ['unsigned' => true])]
    private ?int $bestRating = null;

    #[ORM\Column(length: 8, enumType: StreakStatus::class)]
    private StreakStatus $status = StreakStatus::Active;

    #[ORM\Column(options: ['default' => false])]
    private bool $record = false;

    #[ORM\Column]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $endedAt = null;

    public function __construct(Run $run, Ramp $ramp, string $topic, \DateTimeImmutable $startedAt)
    {
        $this->id = Uuid::v7();
        $this->user = $run->getUser();
        $this->run = $run;
        $this->ramp = $ramp;
        $this->topic = $topic;
        $this->startedAt = $startedAt;
    }

    public function solved(int $rating): void
    {
        if (StreakStatus::Active !== $this->status) {
            throw new \LogicException('Streak over.');
        }
        ++$this->length;
        $this->bestRating = max($this->bestRating ?? 0, $rating);
    }

    /**
     * @param bool $record it beat the user's best of its ramp and topic
     */
    public function end(StreakStatus $status, \DateTimeImmutable $at, bool $record): void
    {
        if (StreakStatus::Active !== $this->status || StreakStatus::Active === $status) {
            throw new \LogicException('Streak already over, or no end.');
        }
        $this->status = $status;
        $this->endedAt = $at;
        $this->record = $record;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getRun(): Run
    {
        return $this->run;
    }

    public function getRamp(): Ramp
    {
        return $this->ramp;
    }

    public function getTopic(): string
    {
        return $this->topic;
    }

    public function getLength(): int
    {
        return $this->length;
    }

    public function getBestRating(): ?int
    {
        return $this->bestRating;
    }

    public function getStatus(): StreakStatus
    {
        return $this->status;
    }

    public function isActive(): bool
    {
        return StreakStatus::Active === $this->status;
    }

    public function isRecord(): bool
    {
        return $this->record;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getEndedAt(): ?\DateTimeImmutable
    {
        return $this->endedAt;
    }
}
