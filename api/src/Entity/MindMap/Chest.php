<?php

declare(strict_types=1);

namespace App\Entity\MindMap;

use App\Entity\User;
use App\Enum\MindMap\Capacity;
use App\Repository\MindMap\ChestRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * The crystals of a step (docs/MIND_MAP.md, § 6): credited once, when the step completes (a chest
 * on the way, a boss beaten); opening the chest only reveals them (shown once, as the sessions').
 * A step whose crystals are paid once per reward key (the défis' opening bosses: once per opening
 * and side, whatever the repertoire, docs/DEFIS.md) keeps it: a second chest of the key is refused.
 */
#[ORM\Entity(repositoryClass: ChestRepository::class)]
#[ORM\Table(name: 'mind_map_chest')]
#[ORM\UniqueConstraint(name: 'uniq_mind_map_chest_user_step', columns: ['user_id', 'capacity', 'step'])]
#[ORM\UniqueConstraint(name: 'uniq_mind_map_chest_user_reward', columns: ['user_id', 'capacity', 'reward_key'])]
#[ORM\Index(name: 'idx_mind_map_chest_user_opened', columns: ['user_id', 'opened_at'])]
class Chest
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 16, enumType: Capacity::class)]
    private Capacity $capacity;

    #[ORM\Column(length: 40, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $step;

    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true])]
    private int $crystals;

    #[ORM\Column(length: 80, nullable: true, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private ?string $rewardKey;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $openedAt = null;

    public function __construct(User $user, Capacity $capacity, string $step, int $crystals, \DateTimeImmutable $createdAt, ?string $rewardKey = null)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->capacity = $capacity;
        $this->step = $step;
        $this->crystals = $crystals;
        $this->createdAt = $createdAt;
        $this->rewardKey = $rewardKey;
    }

    /** Marks it seen (idempotent). */
    public function open(\DateTimeImmutable $at): void
    {
        $this->openedAt ??= $at;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getCapacity(): Capacity
    {
        return $this->capacity;
    }

    public function getStep(): string
    {
        return $this->step;
    }

    public function getCrystals(): int
    {
        return $this->crystals;
    }

    public function getRewardKey(): ?string
    {
        return $this->rewardKey;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getOpenedAt(): ?\DateTimeImmutable
    {
        return $this->openedAt;
    }

    public function isOpened(): bool
    {
        return null !== $this->openedAt;
    }
}
