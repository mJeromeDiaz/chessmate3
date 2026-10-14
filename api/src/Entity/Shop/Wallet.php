<?php

declare(strict_types=1);

namespace App\Entity\Shop;

use App\Entity\User;
use Doctrine\ORM\Mapping as ORM;

/**
 * A player's crowns (docs/SHOP.md): the balance, never negative, changed only under a lock on this
 * row together with a ledger entry ({@see \App\Shop\Wallet\Wallets}).
 */
#[ORM\Entity]
#[ORM\Table(name: 'shop_wallet')]
class Wallet
{
    #[ORM\Id]
    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(options: ['unsigned' => true, 'default' => 0])]
    private int $balance = 0;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $user, \DateTimeImmutable $updatedAt)
    {
        $this->user = $user;
        $this->updatedAt = $updatedAt;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getBalance(): int
    {
        return $this->balance;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
