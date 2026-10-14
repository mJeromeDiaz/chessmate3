<?php

declare(strict_types=1);

namespace App\Entity\Shop;

use App\Entity\User;
use App\Enum\Shop\ItemType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A daily streak freeze a player holds (docs/SHOP.md): its days, and the days it still covers (a
 * week's freeze covering five missed days keeps two). Written in SQL by the purchase and when the
 * streak uses it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'shop_freeze')]
#[ORM\Index(name: 'idx_shop_freeze_user_left', columns: ['user_id', 'days_left'])]
class Freeze
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 24, enumType: ItemType::class)]
    private ItemType $item;

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $days;

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $daysLeft;

    #[ORM\Column]
    private \DateTimeImmutable $acquiredAt;

    public function __construct(User $user, ItemType $item, int $days, \DateTimeImmutable $acquiredAt)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->item = $item;
        $this->days = $days;
        $this->daysLeft = $days;
        $this->acquiredAt = $acquiredAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getItem(): ItemType
    {
        return $this->item;
    }

    public function getDays(): int
    {
        return $this->days;
    }

    public function getDaysLeft(): int
    {
        return $this->daysLeft;
    }

    public function getAcquiredAt(): \DateTimeImmutable
    {
        return $this->acquiredAt;
    }
}
