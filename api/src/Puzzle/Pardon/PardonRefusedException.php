<?php

declare(strict_types=1);

namespace App\Puzzle\Pardon;

/**
 * A pardon refused before any write (docs/PUZZLES.md): not a missed rated puzzle or already
 * pardoned (`not_pardonable`), the rating moved on or another puzzle was served since
 * (`too_late`), none left (`no_pardon`).
 */
final class PardonRefusedException extends \RuntimeException
{
    public const NOT_PARDONABLE = 'not_pardonable';
    public const TOO_LATE = 'too_late';
    public const NO_PARDON = 'no_pardon';

    public function __construct(
        public readonly string $reason,
    ) {
        parent::__construct($reason);
    }
}
