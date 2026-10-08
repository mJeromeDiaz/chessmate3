<?php

declare(strict_types=1);

namespace App\Evaluation\Position;

/**
 * An admin's change to a position is refused (docs/EVALUATION.md): `illegal` FEN, `no_move` (mate
 * or stalemate: nothing to evaluate), `duplicate` FEN, `played` (deactivate it instead).
 */
final class PositionRefusedException extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
