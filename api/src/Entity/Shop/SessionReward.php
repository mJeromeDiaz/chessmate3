<?php

declare(strict_types=1);

namespace App\Entity\Shop;

use App\Entity\User;
use App\Enum\Shop\RewardTier;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * The crowns a completed training session earned (docs/SHOP.md, § 4 bis), credited when it closed:
 * one row per session (the session's id, no link: the store never reaches into Training's tables),
 * its local day (the daily cap), the seconds paid, the tier, and when its chest was opened (shown
 * once). Written in SQL by {@see \App\Shop\Reward\SessionRewarder}.
 */
#[ORM\Entity]
#[ORM\Table(name: 'shop_session_reward')]
#[ORM\UniqueConstraint(name: 'uniq_shop_session_reward_session', columns: ['session_id'])]
#[ORM\Index(name: 'idx_shop_session_reward_user_day', columns: ['user_id', 'local_date'])]
#[ORM\Index(name: 'idx_shop_session_reward_date', columns: ['created_at'])]
class SessionReward
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'uuid')]
    private Uuid $sessionId;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $localDate;

    /** Exercises finished in its rewarded runs (the free study left out). */
    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $itemCount;

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $successCount;

    /** After the daily cap. */
    #[ORM\Column(options: ['unsigned' => true])]
    private int $paidSeconds;

    /** The daily cap cut its paid time. */
    #[ORM\Column]
    private bool $capped;

    #[ORM\Column(length: 8, enumType: RewardTier::class)]
    private RewardTier $tier;

    #[ORM\Column(options: ['unsigned' => true])]
    private int $crowns;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** Its chest opened (set at once when it earned nothing). */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $openedAt = null;

    public function __construct(User $user, Uuid $sessionId, \DateTimeImmutable $localDate, RewardTier $tier, int $crowns, \DateTimeImmutable $createdAt)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->sessionId = $sessionId;
        $this->localDate = $localDate;
        $this->itemCount = 0;
        $this->successCount = 0;
        $this->paidSeconds = 0;
        $this->capped = false;
        $this->tier = $tier;
        $this->crowns = $crowns;
        $this->createdAt = $createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSessionId(): Uuid
    {
        return $this->sessionId;
    }

    public function getLocalDate(): \DateTimeImmutable
    {
        return $this->localDate;
    }

    public function getItemCount(): int
    {
        return $this->itemCount;
    }

    public function getSuccessCount(): int
    {
        return $this->successCount;
    }

    public function getPaidSeconds(): int
    {
        return $this->paidSeconds;
    }

    public function isCapped(): bool
    {
        return $this->capped;
    }

    public function getTier(): RewardTier
    {
        return $this->tier;
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
