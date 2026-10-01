<?php

declare(strict_types=1);

namespace App\Chess;

/**
 * A FEN that is malformed or describes a position that cannot occur (no king, pawn on the first or
 * last rank, side not to move in check...).
 */
final class InvalidPositionException extends \InvalidArgumentException
{
}
