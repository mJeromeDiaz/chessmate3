<?php

declare(strict_types=1);

namespace App\Repertoire\Import;

/**
 * An import that cannot go on: too big, empty, unreadable, over a limit. The reason is a stable
 * code (the front words it); the message is for logs.
 */
final class ImportRejectedException extends \RuntimeException
{
    public const TOO_LARGE = 'too_large';
    public const TOO_MANY_GAMES = 'too_many_games';
    public const TOO_MANY_POSITIONS = 'too_many_positions';
    public const TOO_DEEP = 'too_deep';
    public const EMPTY = 'empty';
    public const SYNTAX = 'syntax';

    public function __construct(
        public readonly string $reason,
        string $message,
        /** PGN line of a syntax error. */
        public readonly ?int $pgnLine = null,
    ) {
        parent::__construct($message);
    }
}
