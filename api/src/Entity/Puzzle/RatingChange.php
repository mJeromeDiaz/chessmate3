<?php

declare(strict_types=1);

namespace App\Entity\Puzzle;

use App\Entity\User;
use App\Enum\Puzzle\RatingChangeReason;
use App\Puzzle\Rating\RatingState;
use App\Repository\Puzzle\RatingChangeRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One step of a user's puzzle rating history (before/after each rated attempt, or the Lichess
 * seed), kept for the future dashboard. Append-only.
 */
#[ORM\Entity(repositoryClass: RatingChangeRepository::class)]
#[ORM\Table(name: 'puzzle_rating_change')]
#[ORM\Index(name: 'idx_puzzle_rating_change_user_date', columns: ['user_id', 'created_at'])]
#[ORM\Index(name: 'idx_puzzle_rating_change_user', columns: ['user_id'])]
class RatingChange
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 20, enumType: RatingChangeReason::class)]
    private RatingChangeReason $reason;

    #[ORM\Column]
    private float $ratingBefore;

    #[ORM\Column]
    private float $ratingAfter;

    #[ORM\Column]
    private float $deviationBefore;

    #[ORM\Column]
    private float $deviationAfter;

    #[ORM\Column]
    private float $volatilityBefore;

    #[ORM\Column]
    private float $volatilityAfter;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, RatingChangeReason $reason, RatingState $before, RatingState $after, \DateTimeImmutable $at)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->reason = $reason;
        $this->ratingBefore = $before->rating;
        $this->ratingAfter = $after->rating;
        $this->deviationBefore = $before->deviation;
        $this->deviationAfter = $after->deviation;
        $this->volatilityBefore = $before->volatility;
        $this->volatilityAfter = $after->volatility;
        $this->createdAt = $at;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getReason(): RatingChangeReason
    {
        return $this->reason;
    }

    public function getRatingBefore(): float
    {
        return $this->ratingBefore;
    }

    public function getRatingAfter(): float
    {
        return $this->ratingAfter;
    }

    public function getRatingDelta(): float
    {
        return $this->ratingAfter - $this->ratingBefore;
    }

    public function getDeviationBefore(): float
    {
        return $this->deviationBefore;
    }

    public function getDeviationAfter(): float
    {
        return $this->deviationAfter;
    }

    public function getVolatilityBefore(): float
    {
        return $this->volatilityBefore;
    }

    public function getVolatilityAfter(): float
    {
        return $this->volatilityAfter;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
