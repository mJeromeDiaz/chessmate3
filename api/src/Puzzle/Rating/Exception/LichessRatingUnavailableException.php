<?php

declare(strict_types=1);

namespace App\Puzzle\Rating\Exception;

/**
 * Lichess has no usable puzzle rating for this account (none played, or API unreachable).
 */
final class LichessRatingUnavailableException extends \RuntimeException
{
}
