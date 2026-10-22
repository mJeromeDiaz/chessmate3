<?php

declare(strict_types=1);

namespace App\Entity\Tablebase;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An answer of the Lichess tablebase, kept so that a position is asked once (docs/ENDGAMES.md,
 * § 2), in the endgame database (docs/DEPLOY_OVH.md, § 3). Identified by the normalized FEN's
 * digest ({@see \App\Chess\Position\PositionKey}), the FEN kept to check it.
 *
 * Mapped for the schema only: rows are read and written by {@see \App\Endgame\Tablebase\Tablebase}
 * in SQL (an upsert, two players may ask the same position at once), and purged by
 * `app:endgame:prune-tablebase` once unread for a while.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'endgame_tablebase')]
#[ORM\Index(name: 'idx_endgame_tablebase_read', columns: ['read_on'])]
class Probe
{
    #[ORM\Id]
    #[ORM\Column(type: Types::BINARY, length: 16, options: ['fixed' => true])]
    private string $fenHash;

    #[ORM\Column(length: 92, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $fen;

    /** @var array<mixed> {@see \App\Endgame\Tablebase\Answer::toStored()} */
    #[ORM\Column(type: Types::JSON)]
    private array $answer;

    #[ORM\Column]
    private \DateTimeImmutable $fetchedAt;

    /** Last day it was read (UTC): rows unread for long are purged. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $readOn;

    /**
     * @param array<mixed> $answer
     */
    public function __construct(string $fenHash, string $fen, array $answer, \DateTimeImmutable $fetchedAt)
    {
        $this->fenHash = $fenHash;
        $this->fen = $fen;
        $this->answer = $answer;
        $this->fetchedAt = $fetchedAt;
        $this->readOn = $fetchedAt->setTime(0, 0);
    }

    public function getFen(): string
    {
        return $this->fen;
    }

    /**
     * @return array<mixed>
     */
    public function getAnswer(): array
    {
        return $this->answer;
    }

    public function getFetchedAt(): \DateTimeImmutable
    {
        return $this->fetchedAt;
    }

    public function getReadOn(): \DateTimeImmutable
    {
        return $this->readOn;
    }
}
