<?php

declare(strict_types=1);

namespace App\Repertoire\Auto;

/**
 * A repertoire from the user's games refused, with a stable reason the front words.
 */
final class AutoBuildException extends \RuntimeException
{
    /** No Lichess account linked to read the games of. */
    public const LICHESS_NOT_LINKED = 'lichess_not_linked';
    /** Games wanted or speeds not among those offered. */
    public const INVALID_SETTINGS = 'invalid_settings';
    /** Families, moves or lines that are not the build's. */
    public const INVALID_SELECTION = 'invalid_selection';
    /** Still downloading, or already applied. */
    public const NOT_READY = 'not_ready';
    public const ALREADY_APPLIED = 'already_applied';
    /** No opening chosen, or the chosen ones give no line. */
    public const NOTHING_SELECTED = 'nothing_selected';

    public function __construct(
        public readonly string $reason,
    ) {
        parent::__construct($reason);
    }
}
