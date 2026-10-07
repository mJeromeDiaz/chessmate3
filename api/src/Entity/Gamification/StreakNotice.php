<?php

declare(strict_types=1);

namespace App\Entity\Gamification;

use App\Entity\User;
use App\Enum\Gamification\Trophy;
use App\Repository\Gamification\StreakNoticeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * The streak announcement of a user's last active day (docs/GAMIFICATION.md, "Annonce de la
 * série"): one row per user, overwritten by the first exercise of each new local day, until the
 * front shows it (acknowledgedAt). Written in plain SQL by
 * {@see \App\Gamification\Streak\StreakNoticeWriter}.
 */
#[ORM\Entity(repositoryClass: StreakNoticeRepository::class, readOnly: true)]
#[ORM\Table(name: 'gamification_streak_notice')]
#[ORM\UniqueConstraint(name: 'uniq_gamification_streak_notice_user', columns: ['user_id'])]
class StreakNotice
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        /** The local day it announces. */
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private \DateTimeImmutable $localDate,
        /** The streak that day reached. */
        #[ORM\Column]
        private int $streak,
        /** The streak before that day (0: it had broken). */
        #[ORM\Column]
        private int $previousStreak,
        /** The streak badge reached that day. */
        #[ORM\Column(length: 32, nullable: true, enumType: Trophy::class)]
        private ?Trophy $badge = null,
        #[ORM\Column(nullable: true)]
        private ?\DateTimeImmutable $acknowledgedAt = null,
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

    public function getLocalDate(): \DateTimeImmutable
    {
        return $this->localDate;
    }

    public function getStreak(): int
    {
        return $this->streak;
    }

    public function getPreviousStreak(): int
    {
        return $this->previousStreak;
    }

    public function getBadge(): ?Trophy
    {
        return $this->badge;
    }

    public function getAcknowledgedAt(): ?\DateTimeImmutable
    {
        return $this->acknowledgedAt;
    }
}
