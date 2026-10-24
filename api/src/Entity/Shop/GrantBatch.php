<?php

declare(strict_types=1);

namespace App\Entity\Shop;

use App\Entity\User;
use App\Enum\Shop\ItemType;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A gift to every active account by an admin (docs/SHOP.md, § 4 quater): the journal's line, one
 * {@see ItemGrant} per player served. Written in SQL by {@see \App\Shop\Gift\TicketGrants}; the
 * entity maps the schema.
 */
#[ORM\Entity]
#[ORM\Table(name: 'shop_grant_batch')]
#[ORM\Index(name: 'idx_shop_grant_batch_created', columns: ['created_at'])]
class GrantBatch
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 24, enumType: ItemType::class)]
    private ItemType $item;

    #[ORM\Column(options: ['unsigned' => true])]
    private int $quantity;

    /** Shown to the players. */
    #[ORM\Column(length: 255)]
    private string $message;

    /** The players served. */
    #[ORM\Column(options: ['unsigned' => true])]
    private int $recipients;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $admin;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(ItemType $item, int $quantity, string $message, int $recipients, ?User $admin, \DateTimeImmutable $createdAt)
    {
        $this->id = Uuid::v7();
        $this->item = $item;
        $this->quantity = $quantity;
        $this->message = $message;
        $this->recipients = $recipients;
        $this->admin = $admin;
        $this->createdAt = $createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
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

    public function getRecipients(): int
    {
        return $this->recipients;
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
