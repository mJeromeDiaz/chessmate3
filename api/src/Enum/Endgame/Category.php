<?php

declare(strict_types=1);

namespace App\Enum\Endgame;

/**
 * A category of the Lichess tablebase (tablebase.lichess.ovh, docs/ENDGAMES.md), for the side to
 * move. Stored in the kept answers: add cases, never rename one.
 */
enum Category: string
{
    case Win = 'win';
    /** A win whose distance to mate is not known (6 and 7 pieces). */
    case SyzygyWin = 'syzygy-win';
    /** A win, or a draw by the 50-move rule: the rounded DTZ cannot tell. */
    case MaybeWin = 'maybe-win';
    /** A win spoiled by the 50-move rule. */
    case CursedWin = 'cursed-win';
    case Draw = 'draw';
    /** A loss saved by the 50-move rule. */
    case BlessedLoss = 'blessed-loss';
    case MaybeLoss = 'maybe-loss';
    case SyzygyLoss = 'syzygy-loss';
    case Loss = 'loss';
    case Unknown = 'unknown';

    /**
     * The result under the 50-move rule, null when the tablebase cannot tell it for sure.
     */
    public function result(): ?Result
    {
        return match ($this) {
            self::Win, self::SyzygyWin => Result::Win,
            self::CursedWin, self::Draw, self::BlessedLoss => Result::Draw,
            self::Loss, self::SyzygyLoss => Result::Loss,
            self::MaybeWin, self::MaybeLoss, self::Unknown => null,
        };
    }
}
