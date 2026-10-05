<?php

declare(strict_types=1);

namespace App\Entity\Gamification;

use App\Entity\User;
use App\Enum\Gamification\Trophy;
use App\Repository\Gamification\TrophyUnlockRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A trophy a user won (docs/GAMIFICATION.md), once, at the date of the feat (not of its
 * detection). Written by {@see \App\Gamification\Trophy\TrophyEvaluator}.
 */
#[ORM\Entity(repositoryClass: TrophyUnlockRepository::class, readOnly: true)]
#[ORM\Table(name: 'gamification_trophy')]
#[ORM\UniqueConstraint(name: 'uniq_gamification_trophy_user_trophy', columns: ['user_id', 'trophy'])]
class TrophyUnlock
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\Column(length: 32, enumType: Trophy::class)]
        private Trophy $trophy,
        #[ORM\Column]
        private \DateTimeImmutable $unlockedAt,
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

    public function getTrophy(): Trophy
    {
        return $this->trophy;
    }

    public function getUnlockedAt(): \DateTimeImmutable
    {
        return $this->unlockedAt;
    }
}
