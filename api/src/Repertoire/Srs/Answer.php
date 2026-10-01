<?php

declare(strict_types=1);

namespace App\Repertoire\Srs;

use App\Enum\Repertoire\Rating;
use Symfony\Component\Uid\Uuid;

/**
 * The outcome of one answer ({@see Reviewer::answer()}).
 */
final readonly class Answer
{
    public function __construct(
        public bool $correct,
        public string $expectedUci,
        public string $expectedSan,
        public Rating $rating,
        /** Whether the card went through the scheduler (a right answer does only when due). */
        public bool $updated,
        public Uuid $cardId,
        /** The card after the answer (unchanged when not updated). */
        public Card $card,
    ) {
    }
}
