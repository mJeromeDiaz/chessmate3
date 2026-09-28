<?php

declare(strict_types=1);

namespace App\Puzzle\Rating\Exception;

/**
 * The user has no linked Lichess account.
 */
final class LichessNotLinkedException extends \RuntimeException
{
}
