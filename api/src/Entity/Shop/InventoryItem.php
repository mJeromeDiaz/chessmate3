<?php

declare(strict_types=1);

namespace App\Entity\Shop;

use App\Entity\User;
use App\Enum\Shop\ItemType;
use Doctrine\ORM\Mapping as ORM;

/**
 * How many of an item a player holds (docs/SHOP.md), freezes aside ({@see Freeze}: each keeps its
 * days left). Written in SQL by the purchase and by the effects that use an item.
 */
#[ORM\Entity]
#[ORM\Table(name: 'shop_inventory_item')]
class InventoryItem
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Id]
    #[ORM\Column(length: 24, enumType: ItemType::class)]
    private ItemType $item;

    #[ORM\Column(options: ['unsigned' => true, 'default' => 0])]
    private int $quantity = 0;

    public function __construct(User $user, ItemType $item)
    {
        $this->user = $user;
        $this->item = $item;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getItem(): ItemType
    {
        return $this->item;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }
}
