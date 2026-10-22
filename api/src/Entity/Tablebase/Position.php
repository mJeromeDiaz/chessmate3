<?php

declare(strict_types=1);

namespace App\Entity\Tablebase;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A position served by a lesson of the endgames module (docs/ENDGAMES.md, § 4), already checked by
 * the tablebase: the textbook position and its variants, drawn by `app:endgame:fill-pool`, in the
 * endgame database. `randomKey` picks one at random through an index.
 *
 * Mapped for the schema only: rows are read and written by {@see \App\Endgame\Pool\PositionPool}.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'endgame_position')]
#[ORM\UniqueConstraint(name: 'uniq_endgame_position_lesson_fen', columns: ['lesson_key', 'fen_hash'])]
#[ORM\Index(name: 'idx_endgame_position_pick', columns: ['lesson_key', 'random_key'])]
class Position
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(options: ['unsigned' => true])]
    private ?int $id = null;

    /** {@see \App\Endgame\Lesson\Catalogue} */
    #[ORM\Column(length: 32, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $lessonKey;

    #[ORM\Column(length: 92, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $fen;

    #[ORM\Column(type: Types::BINARY, length: 16, options: ['fixed' => true])]
    private string $fenHash;

    /** Plies to mate with perfect play, when the tablebase knows it (5 pieces at most). */
    #[ORM\Column(type: Types::SMALLINT, nullable: true, options: ['unsigned' => true])]
    private ?int $dtm = null;

    #[ORM\Column]
    private bool $textbook = false;

    #[ORM\Column(options: ['unsigned' => true])]
    private int $randomKey;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $lessonKey, string $fen, string $fenHash, int $randomKey, \DateTimeImmutable $createdAt)
    {
        $this->lessonKey = $lessonKey;
        $this->fen = $fen;
        $this->fenHash = $fenHash;
        $this->randomKey = $randomKey;
        $this->createdAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLessonKey(): string
    {
        return $this->lessonKey;
    }

    public function getFen(): string
    {
        return $this->fen;
    }

    public function getDtm(): ?int
    {
        return $this->dtm;
    }

    public function isTextbook(): bool
    {
        return $this->textbook;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
