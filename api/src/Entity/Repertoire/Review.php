<?php

declare(strict_types=1);

namespace App\Entity\Repertoire;

use App\Entity\Training\Run;
use App\Enum\Repertoire\Rating;
use App\Repository\Repertoire\ReviewRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One answer given on a card, append-only (docs/REPERTOIRE.md): every answer is logged, including
 * a right one on a card that was not due and so left it unchanged ($updated false).
 *
 * Read-only for the ORM: rows are written by App\Repertoire\Srs\Reviewer only.
 */
#[ORM\Entity(repositoryClass: ReviewRepository::class, readOnly: true)]
#[ORM\Table(name: 'repertoire_review')]
#[ORM\Index(name: 'idx_repertoire_review_card_reviewed', columns: ['card_id', 'reviewed_at'])]
#[ORM\Index(name: 'idx_repertoire_review_run', columns: ['run_id'])]
class Review
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Card::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Card $card;

    /** The timed run it was given in, if any. */
    #[ORM\ManyToOne(targetEntity: Run::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Run $run;

    #[ORM\Column(length: 5, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $playedUci;

    #[ORM\Column]
    private bool $correct;

    #[ORM\Column(type: Types::SMALLINT, enumType: Rating::class, options: ['unsigned' => true])]
    private Rating $rating;

    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true])]
    private int $thinkMs;

    /** Whether the answer went through the scheduler (a right one does only when the card is due). */
    #[ORM\Column]
    private bool $updated;

    /**
     * The card's memory before the answer, and after it when updated
     * ({state, step, stability, difficulty, due, lastReview}).
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(name: 'card_before', type: Types::JSON)]
    private array $before;

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'card_after', type: Types::JSON, nullable: true)]
    private ?array $after;

    #[ORM\Column]
    private \DateTimeImmutable $reviewedAt;

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCard(): Card
    {
        return $this->card;
    }

    public function getRun(): ?Run
    {
        return $this->run;
    }

    public function getPlayedUci(): string
    {
        return $this->playedUci;
    }

    public function isCorrect(): bool
    {
        return $this->correct;
    }

    public function getRating(): Rating
    {
        return $this->rating;
    }

    public function getThinkMs(): int
    {
        return $this->thinkMs;
    }

    public function isUpdated(): bool
    {
        return $this->updated;
    }

    /**
     * @return array<string, mixed>
     */
    public function getBefore(): array
    {
        return $this->before;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getAfter(): ?array
    {
        return $this->after;
    }

    public function getReviewedAt(): \DateTimeImmutable
    {
        return $this->reviewedAt;
    }
}
