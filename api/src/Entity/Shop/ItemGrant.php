<?php

declare(strict_types=1);

namespace App\Entity\Shop;

use App\Entity\User;
use App\Enum\Shop\ItemType;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Items given to a player by an admin (docs/SHOP.md, § 4 quater): the player's history, the
 * message shown at the next visit (`seenAt` null until then), its {@see GrantBatch} for a gift to
 * everyone. Written in SQL by {@see \App\Shop\Gift\TicketGrants}; the entity maps the schema.
 */
#[ORM\Entity]
#[ORM\Table(name: 'shop_item_grant')]
#[ORM\Index(name: 'idx_shop_item_grant_user_seen', columns: ['user_id', 'seen_at'])]
class ItemGrant
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 24, enumType: ItemType::class)]
    private ItemType $item;

    #[ORM\Column(options: ['unsigned' => true])]
    private int $quantity;

    /** Shown to the player. */
    #[ORM\Column(length: 255)]
    private string $message;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $admin;

    /** The gift to everyone it was part of, null for a gift to this player alone. */
    #[ORM\ManyToOne(targetEntity: GrantBatch::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?GrantBatch $batch;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** When the player saw its message. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $seenAt = null;

    public function __construct(User $user, ItemType $item, int $quantity, string $message, ?User $admin, ?GrantBatch $batch, \DateTimeImmutable $createdAt)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->item = $item;
        $this->quantity = $quantity;
        $this->message = $message;
        $this->admin = $admin;
        $this->batch = $batch;
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

    public function getItem(): ItemType
    {
        return $this->item;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getAdmin(): ?User
    {
        return $this->admin;
    }

    public function getBatch(): ?GrantBatch
    {
        return $this->batch;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getSeenAt(): ?\DateTimeImmutable
    {
        return $this->seenAt;
    }
}
