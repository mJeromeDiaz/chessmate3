<?php

declare(strict_types=1);

namespace App\Entity\Shop;

use App\Entity\User;
use App\Enum\Shop\LedgerKind;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One movement of a player's crowns (docs/SHOP.md): a purchase (its offer and quantity), an
 * admin's grant or revocation (its reason). Written by {@see \App\Shop\Wallet\Wallets}.
 */
#[ORM\Entity]
#[ORM\Table(name: 'shop_ledger_entry')]
#[ORM\Index(name: 'idx_shop_ledger_user_date', columns: ['user_id', 'created_at'])]
#[ORM\Index(name: 'idx_shop_ledger_kind_date', columns: ['kind', 'created_at'])]
class LedgerEntry
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 16, enumType: LedgerKind::class)]
    private LedgerKind $kind;

    /** Crowns in (positive) or out (negative). */
    #[ORM\Column]
    private int $delta;

    #[ORM\Column(options: ['unsigned' => true])]
    private int $balanceAfter;

    #[ORM\ManyToOne(targetEntity: Offer::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Offer $offer = null;

    /** The offer's name when bought (it may change or go). */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $offerName = null;

    #[ORM\Column(type: 'smallint', nullable: true, options: ['unsigned' => true])]
    private ?int $quantity = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $reason = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $admin = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, LedgerKind $kind, int $delta, int $balanceAfter, \DateTimeImmutable $createdAt)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->kind = $kind;
        $this->delta = $delta;
        $this->balanceAfter = $balanceAfter;
        $this->createdAt = $createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getKind(): LedgerKind
    {
        return $this->kind;
    }

    public function getDelta(): int
    {
        return $this->delta;
    }

    public function getBalanceAfter(): int
    {
        return $this->balanceAfter;
    }

    public function getOffer(): ?Offer
    {
        return $this->offer;
    }

    public function getOfferName(): ?string
    {
        return $this->offerName;
    }

    public function getQuantity(): ?int
    {
        return $this->quantity;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function getAdmin(): ?User
    {
        return $this->admin;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
