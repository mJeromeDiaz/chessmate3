<?php

declare(strict_types=1);

namespace App\Puzzle\Solution;

/**
 * The submitted move log cannot come from an honest client: malformed UCI, illegal move, moves
 * after the end of the puzzle. The attempt stays pending; the client gets a 400.
 */
final class InvalidSubmissionException extends \RuntimeException
{
}
