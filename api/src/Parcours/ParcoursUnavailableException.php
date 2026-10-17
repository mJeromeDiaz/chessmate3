<?php

declare(strict_types=1);

namespace App\Parcours;

/**
 * A parcours that cannot be launched (409, `detail` = the reason): `no_opening` (no family of the
 * repertoire has enough puzzles), `empty_repertoire` (no line to review).
 */
final class ParcoursUnavailableException extends \RuntimeException
{
    public const NO_OPENING = 'no_opening';
    public const EMPTY_REPERTOIRE = 'empty_repertoire';

    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
