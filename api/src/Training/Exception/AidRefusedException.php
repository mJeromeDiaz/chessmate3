<?php

declare(strict_types=1);

namespace App\Training\Exception;

/**
 * A paid aid of the store asked on an item and refused (docs/PUZZLE_STREAK.md, docs/BLINDFOLD.md):
 * none left (`no_help`, `no_pass`, `no_pardon`), or no more on this item (`help_limit`), or none in
 * this module (`not_offered`), or a pardon on an item that takes none (`not_pardonable`) or that
 * the run left behind (`too_late`). Thrown before any write.
 */
final class AidRefusedException extends \RuntimeException
{
    public const NO_HELP = 'no_help';
    public const NO_PASS = 'no_pass';
    public const HELP_LIMIT = 'help_limit';
    public const NOT_OFFERED = 'not_offered';
    public const NO_PARDON = 'no_pardon';
    public const NOT_PARDONABLE = 'not_pardonable';
    public const TOO_LATE = 'too_late';

    public function __construct(
        public readonly string $reason,
    ) {
        parent::__construct($reason);
    }
}
