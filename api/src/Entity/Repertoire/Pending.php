<?php

declare(strict_types=1);

namespace App\Entity\Repertoire;

use App\Chess\Position\PositionKey;
use App\Enum\Repertoire\PendingReason;
use App\Enum\Repertoire\PendingStatus;
use App\Repository\Repertoire\PendingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A position of a repertoire built from the user's games left without a prepared move
 * (docs/REPERTOIRE.md, "En suspens"): the user is to move there, reached by a popular reply of the
 * Lichess explorer (or where a short line they set aside left the others). Written when the
 * repertoire is created; it never changes the repertoire by itself: answering it adds the user's
 * move through the import path (App\Repertoire\Auto\PendingService).
 */
#[ORM\Entity(repositoryClass: PendingRepository::class)]
#[ORM\Table(name: 'repertoire_pending')]
#[ORM\UniqueConstraint(name: 'uniq_repertoire_pending_position', columns: ['repertoire_id', 'fen_hash'])]
class Pending
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Repertoire::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Repertoire $repertoire;

    #[ORM\Column(type: Types::BINARY, length: 16, options: ['fixed' => true])]
    private string $fenHash;

    /** The position (normalized FEN), the user to move. */
    #[ORM\Column(length: 92, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $fen;

    /**
     * The SAN moves from the initial position to it.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $path;

    #[ORM\Column(length: 16, enumType: PendingReason::class)]
    private PendingReason $reason;

    /** The reply that leads there on Lichess: its games, share (%) and the user's score (%); null for a short line. */
    #[ORM\Column(nullable: true, options: ['unsigned' => true])]
    private ?int $games;

    #[ORM\Column(nullable: true)]
    private ?float $share;

    #[ORM\Column(type: Types::SMALLINT, nullable: true, options: ['unsigned' => true])]
    private ?int $score;

    #[ORM\Column(length: 12, enumType: PendingStatus::class)]
    private PendingStatus $status = PendingStatus::Open;

    /** The user's answer (UCI), once answered. */
    #[ORM\Column(length: 5, nullable: true, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private ?string $answerUci = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param list<string> $path
     */
    public function __construct(Repertoire $repertoire, string $fen, array $path, PendingReason $reason, ?int $games, ?float $share, ?int $score, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->repertoire = $repertoire;
        $this->fen = $fen;
        $this->fenHash = PositionKey::of($fen)->hash;
        $this->path = $path;
        $this->reason = $reason;
        $this->games = $games;
        $this->share = $share;
        $this->score = $score;
        $this->createdAt = $now;
        $this->updatedAt = $now;
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

    /**
     * @return list<string>
     */
    public function getPath(): array
    {
        return $this->path;
    }

    public function getReason(): PendingReason
    {
        return $this->reason;
    }

    public function getGames(): ?int
    {
        return $this->games;
    }

    public function getShare(): ?float
    {
        return $this->share;
    }

    public function getScore(): ?int
    {
        return $this->score;
    }

    public function getStatus(): PendingStatus
    {
        return $this->status;
    }

    public function getAnswerUci(): ?string
    {
        return $this->answerUci;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function answer(string $uci, \DateTimeImmutable $now): void
    {
        $this->status = PendingStatus::Answered;
        $this->answerUci = $uci;
        $this->updatedAt = $now;
    }

    public function dismiss(\DateTimeImmutable $now): void
    {
        $this->status = PendingStatus::Dismissed;
        $this->updatedAt = $now;
    }

    /** Open again: restored after being dismissed, or its answer undone. */
    public function reopen(\DateTimeImmutable $now): void
    {
        $this->status = PendingStatus::Open;
        $this->answerUci = null;
        $this->updatedAt = $now;
    }
}
