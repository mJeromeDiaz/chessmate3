<?php

declare(strict_types=1);

namespace App\Chess\Pgn;

/**
 * The text is not valid PGN (unclosed comment or variation, malformed tag, unexpected character...).
 * $pgnLine is the 1-based line of the text where the problem was found.
 */
final class PgnSyntaxException extends \InvalidArgumentException
{
    public function __construct(string $message, public readonly int $pgnLine)
    {
        parent::__construct(sprintf('%s (line %d)', $message, $pgnLine));
    }
}
