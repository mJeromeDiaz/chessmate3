<?php

declare(strict_types=1);

namespace App\Entity\Arena;

use App\Entity\User;
use App\Repository\Arena\UnlockRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A boss the player can challenge in the arena, since when, and whether its arrival was shown (the
 * animated reveal, docs/ARENA.md, § 5). Written when the arena is read, once per boss, or when the
 * boss is bought (§ 6 bis).
 */
#[ORM\Entity(repositoryClass: UnlockRepository::class)]
#[ORM\Table(name: 'arena_unlock')]
#[ORM\UniqueConstraint(name: 'uniq_arena_unlock_user_boss', columns: ['user_id', 'boss'])]
class Unlock
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 32, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $boss;

    #[ORM\Column]
    private \DateTimeImmutable $unlockedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $announcedAt = null;

    /** Bought in the shop: when, and its price in crystals. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $purchasedAt = null;

    #[ORM\Column(type: 'smallint', nullable: true, options: ['unsigned' => true])]
    private ?int $price = null;

    public function __construct(User $user, string $boss, \DateTimeImmutable $unlockedAt)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->boss = $boss;
        $this->unlockedAt = $unlockedAt;
    }

    public function announce(\DateTimeImmutable $at): void
    {
        $this->announcedAt ??= $at;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getBoss(): string
    {
        return $this->boss;
    }

    public function getUnlockedAt(): \DateTimeImmutable
    {
        return $this->unlockedAt;
    }

    public function getAnnouncedAt(): ?\DateTimeImmutable
    {
        return $this->announcedAt;
    }

    public function getPurchasedAt(): ?\DateTimeImmutable
    {
        return $this->purchasedAt;
    }

    public function getPrice(): ?int
    {
        return $this->price;
    }
}
