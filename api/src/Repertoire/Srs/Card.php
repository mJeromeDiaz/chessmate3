<?php

declare(strict_types=1);

namespace App\Repertoire\Srs;

use App\Enum\Repertoire\CardState;

/**
 * The memory state of one card (FSRS): immutable, each review gives a new one
 * ({@see Fsrs::review()}). A new card is learning, at its first step, due at once.
 */
final readonly class Card
{
    public function __construct(
        public CardState $state,
        /** Learning or relearning step; null in review. */
        public ?int $step,
        /** Days for the retrievability to fall to 90 %; null before the first review. */
        public ?float $stability,
        /** 1 (easy) to 10 (hard); null before the first review. */
        public ?float $difficulty,
        public \DateTimeImmutable $due,
        public ?\DateTimeImmutable $lastReview,
    ) {
    }

    public static function fresh(\DateTimeImmutable $now): self
    {
        return new self(CardState::Learning, 0, null, null, $now, null);
    }

    public function isDue(\DateTimeImmutable $now): bool
    {
        return $this->due <= $now;
    }

    /**
     * JSON-safe (the review log): instants in ISO 8601, UTC.
     *
     * @return array{state: int, step: int|null, stability: float|null, difficulty: float|null, due: string, lastReview: string|null}
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state->value,
            'step' => $this->step,
            'stability' => $this->stability,
            'difficulty' => $this->difficulty,
            'due' => $this->due->format(\DATE_ATOM),
            'lastReview' => $this->lastReview?->format(\DATE_ATOM),
        ];
    }
}
