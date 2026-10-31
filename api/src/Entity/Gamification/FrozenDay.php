<?php

declare(strict_types=1);

namespace App\Entity\Gamification;

use App\Entity\User;
use Doctrine\ORM\Mapping as ORM;

/**
 * A local day the daily streak went on without an exercise, covered by a freeze (docs/SHOP.md,
 * "Gel de série"): it keeps the streak alive without counting in it. Written in SQL by
 * {@see \App\Gamification\Streak\StreakDays::freezeGap()}.
 */
#[ORM\Entity]
#[ORM\Table(name: 'gamification_frozen_day')]
class FrozenDay
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Id]
    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $localDate;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, \DateTimeImmutable $localDate, \DateTimeImmutable $createdAt)
    {
        $this->user = $user;
        $this->localDate = $localDate;
        $this->createdAt = $createdAt;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getLocalDate(): \DateTimeImmutable
    {
        return $this->localDate;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
