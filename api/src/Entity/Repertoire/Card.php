<?php

declare(strict_types=1);

namespace App\Entity\Repertoire;

use App\Enum\Repertoire\CardState;
use App\Repository\Repertoire\CardRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * The spaced-repetition memory (FSRS) of one prepared move (docs/REPERTOIRE.md): identified by the
 * repertoire, the position (its normalized FEN) and the expected move, not by the move's row. A row
 * is created at the first answer: a reference move without one is a new card, due at once.
 *
 * A card is active while a reference move of its repertoire has its key; nothing is written when
 * the repertoire changes. A replaced move leaves its card behind and the new move starts afresh; a
 * move coming back (undo, restoration from the trash, the same move entered again) finds its
 * memory. Cards are deleted with their repertoire only.
 *
 * Read-only for the ORM: rows are written by App\Repertoire\Srs\CardStore only.
 */
#[ORM\Entity(repositoryClass: CardRepository::class, readOnly: true)]
#[ORM\Table(name: 'repertoire_card')]
#[ORM\UniqueConstraint(name: 'uniq_repertoire_card_key', columns: ['repertoire_id', 'fen_hash', 'uci'])]
#[ORM\Index(name: 'idx_repertoire_card_repertoire_due', columns: ['repertoire_id', 'due'])]
#[ORM\Index(name: 'idx_repertoire_card_repertoire', columns: ['repertoire_id'])]
class Card
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Repertoire::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Repertoire $repertoire;

    /** The position, as repertoire_position.fen_hash. */
    #[ORM\Column(type: Types::BINARY, length: 16, options: ['fixed' => true])]
    private string $fenHash;

    /** Kept for reading a card whose move left the repertoire. */
    #[ORM\Column(length: 92, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $fen;

    /** The expected move. */
    #[ORM\Column(length: 5, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $uci;

    #[ORM\Column(type: Types::SMALLINT, enumType: CardState::class, options: ['unsigned' => true])]
    private CardState $state;

    #[ORM\Column(type: Types::SMALLINT, nullable: true, options: ['unsigned' => true])]
    private ?int $step;

    #[ORM\Column(nullable: true)]
    private ?float $stability;

    #[ORM\Column(nullable: true)]
    private ?float $difficulty;

    #[ORM\Column]
    private \DateTimeImmutable $due;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastReview;

    /** Reviews that updated the card. */
    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true, 'default' => 0])]
    private int $reps = 0;

    /** Times forgotten in review. */
    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true, 'default' => 0])]
    private int $lapses = 0;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getRepertoire(): Repertoire
    {
        return $this->repertoire;
    }

    public function getFenHash(): string
    {
        return $this->fenHash;
    }

    public function getFen(): string
    {
        return $this->fen;
    }

    public function getUci(): string
    {
        return $this->uci;
    }

    public function getState(): CardState
    {
        return $this->state;
    }

    public function getStep(): ?int
    {
        return $this->step;
    }

    public function getStability(): ?float
    {
        return $this->stability;
    }

    public function getDifficulty(): ?float
    {
        return $this->difficulty;
    }

    public function getDue(): \DateTimeImmutable
    {
        return $this->due;
    }

    public function getLastReview(): ?\DateTimeImmutable
    {
        return $this->lastReview;
    }

    public function getReps(): int
    {
        return $this->reps;
    }

    public function getLapses(): int
    {
        return $this->lapses;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
