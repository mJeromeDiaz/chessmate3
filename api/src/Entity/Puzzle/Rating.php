<?php

declare(strict_types=1);

namespace App\Entity\Puzzle;

use App\Entity\User;
use App\Enum\Puzzle\RatingSource;
use App\Puzzle\Rating\RatingCalculator;
use App\Puzzle\Rating\RatingState;
use App\Repository\Puzzle\RatingRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A user's current Glicko-2 puzzle rating (one row per user, created on the first puzzle).
 *
 * Every write happens under a pessimistic lock on this row ({@see RatingRepository::lockForUser()}),
 * which also serialises the user's puzzle starts and submissions.
 */
#[ORM\Entity(repositoryClass: RatingRepository::class)]
#[ORM\Table(name: 'puzzle_rating')]
class Rating
{
    #[ORM\Id]
    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column]
    private float $rating;

    #[ORM\Column]
    private float $deviation;

    #[ORM\Column]
    private float $volatility;

    #[ORM\Column(options: ['unsigned' => true, 'default' => 0])]
    private int $ratedCount = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastRatedAt = null;

    #[ORM\Column(length: 20, enumType: RatingSource::class)]
    private RatingSource $source = RatingSource::Default;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $user)
    {
        $this->user = $user;
        $this->apply(RatingCalculator::initial(), new \DateTimeImmutable());
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getRating(): float
    {
        return $this->rating;
    }

    public function getDeviation(): float
    {
        return $this->deviation;
    }

    public function getVolatility(): float
    {
        return $this->volatility;
    }

    public function getState(): RatingState
    {
        return new RatingState($this->rating, $this->deviation, $this->volatility);
    }

    public function getRatedCount(): int
    {
        return $this->ratedCount;
    }

    public function getLastRatedAt(): ?\DateTimeImmutable
    {
        return $this->lastRatedAt;
    }

    public function getSource(): RatingSource
    {
        return $this->source;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * A rating stays provisional while it is still very uncertain (same threshold as Lichess).
     */
    public function isProvisional(): bool
    {
        return $this->deviation > 110;
    }

    public function recordAttempt(RatingState $state, \DateTimeImmutable $at): void
    {
        $this->apply($state, $at);
        ++$this->ratedCount;
        $this->lastRatedAt = $at;
    }

    public function seedFromLichess(RatingState $state, \DateTimeImmutable $at): void
    {
        $this->apply($state, $at);
        $this->source = RatingSource::Lichess;
    }

    private function apply(RatingState $state, \DateTimeImmutable $at): void
    {
        $this->rating = $state->rating;
        $this->deviation = $state->deviation;
        $this->volatility = $state->volatility;
        $this->updatedAt = $at;
    }
}
