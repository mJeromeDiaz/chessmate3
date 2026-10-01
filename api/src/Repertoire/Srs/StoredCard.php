<?php

declare(strict_types=1);

namespace App\Repertoire\Srs;

use Symfony\Component\Uid\Uuid;

/**
 * A card as stored ({@see CardStore}): its row id, its memory and its counters.
 */
final readonly class StoredCard
{
    public function __construct(
        public Uuid $id,
        public Card $card,
        /** Reviews that updated the card. */
        public int $reps,
        /** Times forgotten in review. */
        public int $lapses,
    ) {
    }
}
