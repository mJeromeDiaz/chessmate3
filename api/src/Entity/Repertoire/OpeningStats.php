<?php

declare(strict_types=1);

namespace App\Entity\Repertoire;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * The Lichess explorer's replies in the first positions of the game (docs/REPERTOIRE.md,
 * "Repertoire from my games"), precomputed for the analyses' explorer preset
 * (App\Repertoire\Limits::AUTO_EXPLORER_PRESET, the only one: no speed or rating column) and read
 * before any explorer call. Refreshed past App\Repertoire\Limits::$openingStatsTtlDays, by the daily
 * app:repertoire:refresh-opening-stats or on demand.
 *
 * Read-only for the ORM: rows are written and read in SQL by App\Repertoire\Auto\OpeningStatsReader.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'repertoire_opening_stats')]
#[ORM\UniqueConstraint(name: 'uniq_repertoire_opening_stats_fen', columns: ['fen_hash'])]
class OpeningStats
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    /** Digest of the normalized FEN (App\Chess\Position\PositionKey). */
    #[ORM\Column(type: Types::BINARY, length: 16, options: ['fixed' => true])]
    private string $fenHash;

    #[ORM\Column(length: 92, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $fen;

    /** Games of the position. */
    #[ORM\Column(options: ['unsigned' => true])]
    private int $total;

    /**
     * The replies: move (UCI, SAN), games, share (%), results (App\Repertoire\Auto\ReplyRule::compact()).
     *
     * @var list<array<string, mixed>>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $moves;

    #[ORM\Column]
    private \DateTimeImmutable $fetchedAt;

    /**
     * @param list<array<string, mixed>> $moves
     */
    public function __construct(string $fenHash, string $fen, int $total, array $moves, \DateTimeImmutable $fetchedAt)
    {
        $this->id = Uuid::v7();
        $this->fenHash = $fenHash;
        $this->fen = $fen;
        $this->total = $total;
        $this->moves = $moves;
        $this->fetchedAt = $fetchedAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getFen(): string
    {
        return $this->fen;
    }

    public function getFenHash(): string
    {
        return $this->fenHash;
    }

    public function getTotal(): int
    {
        return $this->total;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getMoves(): array
    {
        return $this->moves;
    }

    public function getFetchedAt(): \DateTimeImmutable
    {
        return $this->fetchedAt;
    }
}
