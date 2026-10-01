<?php

declare(strict_types=1);

namespace App\Entity\Repertoire;

use App\Chess\Position\PositionKey;
use App\Entity\User;
use App\Repository\Repertoire\PositionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A position of a repertoire's graph, identified by its normalized FEN (App\Chess\Rules): one row
 * per position, however many move orders reach it (transpositions). $fenHash (16 bytes of SHA-256)
 * is what lookups use; $fen, binary collation, is the truth.
 *
 * $user duplicates the repertoire's owner for idx_repertoire_position_user_hash: "is this position
 * in one of my repertoires?" (future deviation detection in imported games) is one index probe.
 * $depth is the ply of the position on its canonical path (derived, App\Repertoire\Graph\GraphIndexer).
 */
#[ORM\Entity(repositoryClass: PositionRepository::class)]
#[ORM\Table(name: 'repertoire_position')]
#[ORM\UniqueConstraint(name: 'uniq_repertoire_position_fen', columns: ['repertoire_id', 'fen_hash'])]
#[ORM\Index(name: 'idx_repertoire_position_user_hash', columns: ['user_id', 'fen_hash'])]
#[ORM\Index(name: 'idx_repertoire_position_repertoire', columns: ['repertoire_id'])]
#[ORM\Index(name: 'idx_repertoire_position_user', columns: ['user_id'])]
class Position
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Repertoire::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Repertoire $repertoire;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 92, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $fen;

    #[ORM\Column(type: Types::BINARY, length: 16, options: ['fixed' => true])]
    private string $fenHash;

    /** Side to move: 'w' or 'b'. */
    #[ORM\Column(length: 1, options: ['fixed' => true, 'charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $turn;

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $depth;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * @param Uuid|null $id given when a deleted position is restored (undo)
     */
    public function __construct(Repertoire $repertoire, PositionKey $key, int $depth, \DateTimeImmutable $now, ?Uuid $id = null)
    {
        $this->id = $id ?? Uuid::v7();
        $this->repertoire = $repertoire;
        $this->user = $repertoire->getUser();
        $this->fen = $key->fen;
        $this->fenHash = $key->hash;
        $this->turn = explode(' ', $key->fen)[1] ?? 'w';
        $this->depth = $depth;
        $this->createdAt = $now;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getRepertoire(): Repertoire
    {
        return $this->repertoire;
    }

    public function getFen(): string
    {
        return $this->fen;
    }

    public function getFenHash(): string
    {
        return $this->fenHash;
    }

    public function getTurn(): string
    {
        return $this->turn;
    }

    public function isUserTurn(): bool
    {
        return $this->repertoire->isUserTurn($this->turn);
    }

    public function getDepth(): int
    {
        return $this->depth;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
