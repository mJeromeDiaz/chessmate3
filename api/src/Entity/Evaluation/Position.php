<?php

declare(strict_types=1);

namespace App\Entity\Evaluation;

use App\Enum\Evaluation\Plan;
use App\Enum\Evaluation\PositionTag;
use App\Enum\Repertoire\Color;
use App\Repository\Evaluation\PositionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A position to evaluate (docs/EVALUATION.md), entered by an admin: the engine's evaluation, the
 * key ideas shown with the correction and, optionally, the plan (asked only when there is one),
 * Aaron's tip and a tag. Deactivated rather than deleted once played (attempts point to it).
 */
#[ORM\Entity(repositoryClass: PositionRepository::class)]
#[ORM\Table(name: 'evaluation_position')]
#[ORM\UniqueConstraint(name: 'uniq_evaluation_position_fen', columns: ['fen'])]
#[ORM\Index(name: 'idx_evaluation_position_selection', columns: ['active', 'turn', 'rating'])]
class Position
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    /** Normalized FEN, move counters "0 1". */
    #[ORM\Column(length: 100, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $fen;

    /** The side to move. */
    #[ORM\Column(length: 5, enumType: Color::class)]
    private Color $turn;

    /** Engine evaluation, centipawns, White's point of view (±10 000 or more: won). */
    #[ORM\Column]
    private int $evalCp;

    /** Asked to the player only when set. */
    #[ORM\Column(length: 16, nullable: true, enumType: Plan::class)]
    private ?Plan $plan;

    /** @var list<string> the key ideas (1 to 3), shown with the correction */
    #[ORM\Column(type: Types::JSON)]
    private array $ideas;

    /** Aaron's tip before the answer. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $tip;

    #[ORM\Column(length: 12, nullable: true, enumType: PositionTag::class)]
    private ?PositionTag $tag;

    /** Estimated difficulty, Elo-like. */
    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $rating;

    /** Where it comes from (a game, a classic endgame), shown with the correction. */
    #[ORM\Column(length: 160, nullable: true)]
    private ?string $source;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param list<string> $ideas
     */
    public function __construct(string $fen, Color $turn, int $evalCp, ?Plan $plan, array $ideas, ?string $tip, ?PositionTag $tag, int $rating, ?string $source, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->createdAt = $now;
        $this->update($fen, $turn, $evalCp, $plan, $ideas, $tip, $tag, $rating, $source, true, $now);
    }

    /**
     * @param list<string> $ideas
     */
    public function update(string $fen, Color $turn, int $evalCp, ?Plan $plan, array $ideas, ?string $tip, ?PositionTag $tag, int $rating, ?string $source, bool $active, \DateTimeImmutable $now): void
    {
        $this->fen = $fen;
        $this->turn = $turn;
        $this->evalCp = $evalCp;
        $this->plan = $plan;
        $this->ideas = $ideas;
        $this->tip = $tip;
        $this->tag = $tag;
        $this->rating = $rating;
        $this->source = $source;
        $this->active = $active;
        $this->updatedAt = $now;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getFen(): string
    {
        return $this->fen;
    }

    public function getTurn(): Color
    {
        return $this->turn;
    }

    public function getEvalCp(): int
    {
        return $this->evalCp;
    }

    public function getPlan(): ?Plan
    {
        return $this->plan;
    }

    /**
     * @return list<string>
     */
    public function getIdeas(): array
    {
        return $this->ideas;
    }

    public function getTip(): ?string
    {
        return $this->tip;
    }

    public function getTag(): ?PositionTag
    {
        return $this->tag;
    }

    public function getRating(): int
    {
        return $this->rating;
    }

    public function getSource(): ?string
    {
        return $this->source;
    }

    public function isActive(): bool
    {
        return $this->active;
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
