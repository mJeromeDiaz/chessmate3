<?php

declare(strict_types=1);

namespace App\Entity\Shop;

use App\Entity\User;
use App\Enum\Shop\WheelGift;
use App\Enum\Shop\WheelPrize;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A spin of the gift wheel (docs/SHOP.md, § 4 ter): the day's (`dailyReward`) or a ticket's,
 * its prize drawn by the server and credited when written. Written in SQL by
 * {@see \App\Shop\Wheel\WheelSpinner}; the entity maps the schema.
 */
#[ORM\Entity]
#[ORM\Table(name: 'shop_wheel_spin')]
#[ORM\Index(name: 'idx_shop_wheel_spin_user_created', columns: ['user_id', 'created_at'])]
class WheelSpin
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** The day's wheel, null for a ticket's spin. */
    #[ORM\ManyToOne(targetEntity: DailyReward::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?DailyReward $dailyReward;

    #[ORM\Column(length: 10, enumType: WheelPrize::class)]
    private WheelPrize $prize;

    /** What the chests held, for a gift. */
    #[ORM\Column(length: 10, nullable: true, enumType: WheelGift::class)]
    private ?WheelGift $gift;

    /** The crystals credited. */
    #[ORM\Column(options: ['unsigned' => true])]
    private int $crowns;

    /** Spun with a ticket. */
    #[ORM\Column]
    private bool $ticket;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, ?DailyReward $dailyReward, WheelPrize $prize, ?WheelGift $gift, bool $ticket, \DateTimeImmutable $createdAt)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->dailyReward = $dailyReward;
        $this->prize = $prize;
        $this->gift = $gift;
        $this->crowns = $prize->crowns();
        $this->ticket = $ticket;
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

    public function getDailyReward(): ?DailyReward
    {
        return $this->dailyReward;
    }

    public function getPrize(): WheelPrize
    {
        return $this->prize;
    }

    public function getGift(): ?WheelGift
    {
        return $this->gift;
    }

    public function getCrowns(): int
    {
        return $this->crowns;
    }

    public function isTicket(): bool
    {
        return $this->ticket;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
