<?php

declare(strict_types=1);

namespace App\Entity\Coordinates;

use App\Entity\Training\Run;
use App\Entity\User;
use App\Enum\Coordinates\Orientation;
use App\Repository\Coordinates\SeriesRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One coordinates series (docs/COORDINATES.md): the squares drawn at its start, in order, and the
 * answers judged so far (answer i is about square i). Played in one timed run; it validates its
 * orientation when it closes ({@see \App\Coordinates\Series\CoordinateRules}).
 */
#[ORM\Entity(repositoryClass: SeriesRepository::class)]
#[ORM\Table(name: 'coordinates_series')]
#[ORM\UniqueConstraint(name: 'uniq_coordinates_series_run', columns: ['run_id'])]
#[ORM\Index(name: 'idx_coordinates_series_user_started', columns: ['user_id', 'started_at'])]
#[ORM\Index(name: 'idx_coordinates_series_user_validated', columns: ['user_id', 'orientation', 'validated'])]
class Series
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\OneToOne(targetEntity: Run::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Run $run;

    #[ORM\Column(length: 5, enumType: Orientation::class)]
    private Orientation $orientation;

    /** @var list<string> the squares to find, in order ("e4") */
    #[ORM\Column(type: Types::JSON)]
    private array $squares;

    /** @var list<array{square: string, ms: int}> the square clicked for each square of $squares, and the time it took */
    #[ORM\Column(type: Types::JSON)]
    private array $answers = [];

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $answerCount = 0;

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $successCount = 0;

    /** Sum of the answers' times: never more than the server's elapsed time. */
    #[ORM\Column(options: ['unsigned' => true])]
    private int $answeredMs = 0;

    #[ORM\Column(options: ['default' => false])]
    private bool $validated = false;

    #[ORM\Column]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    /**
     * @param list<string> $squares
     */
    public function __construct(Run $run, Orientation $orientation, array $squares)
    {
        $this->id = Uuid::v7();
        $this->user = $run->getUser();
        $this->run = $run;
        $this->orientation = $orientation;
        $this->squares = $squares;
        $this->startedAt = $run->getStartedAt();
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

    public function getOrientation(): Orientation
    {
        return $this->orientation;
    }

    /**
     * @return list<string>
     */
    public function getSquares(): array
    {
        return $this->squares;
    }

    /**
     * @return list<array{square: string, ms: int}>
     */
    public function getAnswers(): array
    {
        return $this->answers;
    }

    public function getAnswerCount(): int
    {
        return $this->answerCount;
    }

    public function getSuccessCount(): int
    {
        return $this->successCount;
    }

    public function getAnsweredMs(): int
    {
        return $this->answeredMs;
    }

    public function isValidated(): bool
    {
        return $this->validated;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function isClosed(): bool
    {
        return null !== $this->closedAt;
    }

    /**
     * Whether answer $index was right, null when it was not given.
     */
    public function isCorrect(int $index): ?bool
    {
        return isset($this->answers[$index]) ? $this->answers[$index]['square'] === $this->squares[$index] : null;
    }

    /**
     * Judges the answer to the next square.
     *
     * @return bool whether it was right
     */
    public function answer(string $square, int $ms): bool
    {
        if ($this->isClosed() || $this->answerCount >= \count($this->squares)) {
            throw new \LogicException('No square left to answer.');
        }
        $correct = $square === $this->squares[$this->answerCount];
        $this->answers[] = ['square' => $square, 'ms' => max(0, $ms)];
        ++$this->answerCount;
        $this->successCount += $correct ? 1 : 0;
        $this->answeredMs += max(0, $ms);

        return $correct;
    }

    public function isExhausted(): bool
    {
        return $this->answerCount >= \count($this->squares);
    }

    public function close(bool $validated, \DateTimeImmutable $closedAt): void
    {
        $this->validated = $validated;
        $this->closedAt = $closedAt;
    }
}
