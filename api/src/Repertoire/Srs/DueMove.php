<?php

declare(strict_types=1);

namespace App\Repertoire\Srs;

use Symfony\Component\Uid\Uuid;

/**
 * A prepared move whose card is due ({@see DueQuery}).
 */
final readonly class DueMove
{
    public function __construct(
        public Uuid $repertoireId,
        public Uuid $moveId,
        public Uuid $positionId,
        /** Null only before the repertoire's derived data were first computed. */
        public ?Uuid $segmentId,
        /** Null for a card never answered (new). */
        public ?\DateTimeImmutable $due,
    ) {
    }
}
