<?php

declare(strict_types=1);

namespace App\Entity\Shop;

use App\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * The daily chest (docs/SHOP.md, § 4 ter): a few crystals drawn at random with the streak's
 * announcement (the first exercise of the local day), plus a streak badge's bonus the day the
 * streak reaches one. One row per player and local day, credited when written; opening the chest
 * only reveals them (shown once). Written in SQL by {@see \App\Shop\Reward\DailyRewarder}.
 */
#[ORM\Entity]
#[ORM\Table(name: 'shop_daily_reward')]
#[ORM\UniqueConstraint(name: 'uniq_shop_daily_reward_user_day', columns: ['user_id', 'local_date'])]
#[ORM\Index(name: 'idx_shop_daily_reward_user_opened', columns: ['user_id', 'opened_at'])]
class DailyReward
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $localDate;

    /** The streak reached that day. */
    #[ORM\Column(options: ['unsigned' => true])]
    private int $streak;

    /** The days of the streak badge won that day, null on other days. */
    #[ORM\Column(type: Types::SMALLINT, nullable: true, options: ['unsigned' => true])]
    private ?int $badgeDays = null;

    /** The daily draw. */
    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $dailyCrowns;

    /** The badge's bonus (0 without a badge). */
    #[ORM\Column(options: ['unsigned' => true])]
    private int $badgeCrowns;

    /** Both together, credited. */
    #[ORM\Column(options: ['unsigned' => true])]
    private int $crowns;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $openedAt = null;

    public function __construct(User $user, \DateTimeImmutable $localDate, int $streak, int $dailyCrowns, \DateTimeImmutable $createdAt)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->localDate = $localDate;
        $this->streak = $streak;
        $this->dailyCrowns = $dailyCrowns;
        $this->badgeCrowns = 0;
        $this->crowns = $dailyCrowns;
        $this->createdAt = $createdAt;
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

    public function getBadgeDays(): ?int
    {
        return $this->badgeDays;
    }

    public function getDailyCrowns(): int
    {
        return $this->dailyCrowns;
    }

    public function getBadgeCrowns(): int
    {
        return $this->badgeCrowns;
    }

    public function getCrowns(): int
    {
        return $this->crowns;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getOpenedAt(): ?\DateTimeImmutable
    {
        return $this->openedAt;
    }
}
