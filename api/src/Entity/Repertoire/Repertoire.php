<?php

declare(strict_types=1);

namespace App\Entity\Repertoire;

use App\Entity\User;
use App\Enum\Repertoire\Color;
use App\Repository\Repertoire\RepertoireRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * An opening repertoire (docs/REPERTOIRE.md): a graph of positions ({@see Position}) linked by
 * moves ({@see Move}), played with one color, from the initial position. Every change goes
 * through App\Repertoire\Graph\GraphEditor, which locks this row first and bumps $version (the
 * editor's revision counter: a stale client gets a 409, the undo journal is keyed on it).
 */
#[ORM\Entity(repositoryClass: RepertoireRepository::class)]
#[ORM\Table(name: 'repertoire')]
#[ORM\Index(name: 'idx_repertoire_user_created', columns: ['user_id', 'created_at'])]
#[ORM\Index(name: 'idx_repertoire_user', columns: ['user_id'])]
class Repertoire
{
    public const NAME_MAX_LENGTH = 80;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: self::NAME_MAX_LENGTH)]
    private string $name;

    #[ORM\Column(length: 8, enumType: Color::class)]
    private Color $color;

    /** Positions of the graph, the initial one included (limit check without a COUNT). */
    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true, 'default' => 1])]
    private int $positionCount = 1;

    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true, 'default' => 0])]
    private int $version = 0;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $user, string $name, Color $color, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->name = $name;
        $this->color = $color;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function rename(string $name, \DateTimeImmutable $now): void
    {
        $this->name = $name;
        $this->updatedAt = $now;
    }

    public function getColor(): Color
    {
        return $this->color;
    }

    /** Whether the user is to move in a position with this side to move ('w' or 'b'). */
    public function isUserTurn(string $turn): bool
    {
        return $this->color->turn() === $turn;
    }

    public function getPositionCount(): int
    {
        return $this->positionCount;
    }

    public function setPositionCount(int $positionCount): void
    {
        $this->positionCount = $positionCount;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    /** A change was applied: next version. */
    public function touch(\DateTimeImmutable $now): int
    {
        $this->updatedAt = $now;

        return ++$this->version;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
