<?php

declare(strict_types=1);

namespace App\Repertoire\Srs;

use App\Enum\Repertoire\Rating;

/**
 * The rating of an answer in a repertoire test (docs/REPERTOIRE.md): a wrong move is Again; a
 * right one is rated by the think time: under 2 s Easy, 2 to 6 s Good, over 6 s Hard.
 *
 * A right answer updates its card only when the card is due ({@see Card::isDue()}): replaying a
 * line learnt this morning must not inflate its stability. A wrong one always does.
 */
final class Grader
{
    /** Under this, a right move is Easy. */
    public const EASY_BELOW_MS = 2000;
    /** Over this, a right move is Hard. */
    public const HARD_ABOVE_MS = 6000;

    public static function rate(bool $correct, int $thinkMs): Rating
    {
        return match (true) {
            !$correct => Rating::Again,
            $thinkMs < self::EASY_BELOW_MS => Rating::Easy,
            $thinkMs > self::HARD_ABOVE_MS => Rating::Hard,
            default => Rating::Good,
        };
    }

    public static function updatesCard(bool $correct, Card $card, \DateTimeImmutable $now): bool
    {
        return !$correct || $card->isDue($now);
    }
}
