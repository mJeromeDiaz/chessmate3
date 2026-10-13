<?php

declare(strict_types=1);

namespace App\Entity\Repertoire;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * What the user's games said of a move of a repertoire built from them (docs/REPERTOIRE.md,
 * "Repertoire from my games"), kept with the repertoire: identified like a card, by the position
 * and the move, so that it survives the edits of the repertoire (a move removed then entered again
 * finds its statistics). Frozen at the build: they describe the games read then.
 *
 * Read-only for the ORM: rows are written by App\Repertoire\Auto\RepertoireWriter only.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'repertoire_game_stat')]
#[ORM\UniqueConstraint(name: 'uniq_repertoire_game_stat_key', columns: ['repertoire_id', 'fen_hash', 'uci'])]
#[ORM\Index(name: 'idx_repertoire_game_stat_repertoire', columns: ['repertoire_id'])]
class GameStat
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Repertoire::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Repertoire $repertoire;

    /** The position the move is played from, as repertoire_position.fen_hash. */
    #[ORM\Column(type: Types::BINARY, length: 16, options: ['fixed' => true])]
    private string $fenHash;

    #[ORM\Column(length: 92, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $fen;

    #[ORM\Column(length: 5, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $uci;

    /** Games that played the move, and their results (the user's). */
    #[ORM\Column(options: ['unsigned' => true])]
    private int $games;

    #[ORM\Column(options: ['unsigned' => true])]
    private int $wins;

    #[ORM\Column(options: ['unsigned' => true])]
    private int $draws;

    #[ORM\Column(options: ['unsigned' => true])]
    private int $losses;

    /** Of these games, those Lichess analysed, and those where the user made a mistake on the way. */
    #[ORM\Column(options: ['unsigned' => true])]
    private int $analysed;

    #[ORM\Column(options: ['unsigned' => true])]
    private int $errored;

    /** The user's average thinking time on the move (centiseconds), when it is theirs and clocks were known. */
    #[ORM\Column(nullable: true, options: ['unsigned' => true])]
    private ?int $thinkCs;

    /** The user's move: analysed games that played it, those where Lichess judged it a mistake, and its best move instead. */
    #[ORM\Column(options: ['unsigned' => true])]
    private int $moveAnalysed;

    #[ORM\Column(options: ['unsigned' => true])]
    private int $moveErrors;

    #[ORM\Column(length: 5, nullable: true, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private ?string $bestUci;

    /** Lichess' cloud evaluation of the position reached, White's point of view, at the end of a line. */
    #[ORM\Column(nullable: true)]
    private ?int $evalCp;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $evalMate;

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

    public function getGames(): int
    {
        return $this->games;
    }

    public function getWins(): int
    {
        return $this->wins;
    }

    public function getDraws(): int
    {
        return $this->draws;
    }

    public function getLosses(): int
    {
        return $this->losses;
    }

    public function getAnalysed(): int
    {
        return $this->analysed;
    }

    public function getErrored(): int
    {
        return $this->errored;
    }

    public function getThinkCs(): ?int
    {
        return $this->thinkCs;
    }

    public function getMoveAnalysed(): int
    {
        return $this->moveAnalysed;
    }

    public function getMoveErrors(): int
    {
        return $this->moveErrors;
    }

    public function getBestUci(): ?string
    {
        return $this->bestUci;
    }

    public function getEvalCp(): ?int
    {
        return $this->evalCp;
    }

    public function getEvalMate(): ?int
    {
        return $this->evalMate;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
