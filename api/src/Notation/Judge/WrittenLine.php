<?php

declare(strict_types=1);

namespace App\Notation\Judge;

use App\Chess\InvalidPositionException;
use App\Chess\Rules;

/**
 * A line written by the player, judged against the expected one (docs/NOTATION.md, § 3, « write »):
 * each half-move is replayed from the position (lenient SAN: capture and check marks, move numbers
 * and annotations not required), in order, and compared with the expected move. Right when every
 * one matches; a different last move that mates is right too (as Lichess accepts it).
 */
final class WrittenLine
{
    /**
     * @param list<string> $written  half-moves as written (English piece letters), move numbers allowed
     * @param list<string> $expected UCI moves of the solution
     *
     * @return array{correct: bool, mistakeAt: int|null, reason: string|null, sans: list<string>}
     *   mistakeAt: the first half-move that is wrong (0-based); reason: illegal (not a legal move
     *   there), wrong (another move), short (missing moves), long (moves after the end); sans: the
     *   half-moves understood, as SAN
     *
     * @throws InvalidPositionException
     */
    public static function judge(string $fen, array $written, array $expected): array
    {
        $tokens = self::tokens($written);
        $rules = Rules::fromFen($fen);
        $sans = [];
        foreach ($tokens as $i => $token) {
            if ($i >= \count($expected)) {
                return ['correct' => false, 'mistakeAt' => $i, 'reason' => 'long', 'sans' => $sans];
            }
            $move = $rules->playSan($token);
            if (null === $move) {
                return ['correct' => false, 'mistakeAt' => $i, 'reason' => 'illegal', 'sans' => $sans];
            }
            $sans[] = $move->san ?? $token;
            if (Rules::uci($move) !== $expected[$i]) {
                $mates = $i === \count($expected) - 1 && $i === \count($tokens) - 1 && $rules->inCheckmate();

                return $mates
                    ? ['correct' => true, 'mistakeAt' => null, 'reason' => null, 'sans' => $sans]
                    : ['correct' => false, 'mistakeAt' => $i, 'reason' => 'wrong', 'sans' => $sans];
            }
        }
        if (\count($tokens) < \count($expected)) {
            return ['correct' => false, 'mistakeAt' => \count($tokens), 'reason' => 'short', 'sans' => $sans];
        }

        return ['correct' => true, 'mistakeAt' => null, 'reason' => null, 'sans' => $sans];
    }

    /**
     * The half-moves of what was written: move numbers (« 1. », « 2... ») and empty pieces left out.
     *
     * @param list<string> $written
     *
     * @return list<string>
     */
    public static function tokens(array $written): array
    {
        $tokens = [];
        foreach ($written as $part) {
            foreach (preg_split('/\s+/', trim($part)) ?: [] as $token) {
                $token = preg_replace('/^\d+\.+/', '', $token) ?? '';
                if ('' !== $token) {
                    $tokens[] = $token;
                }
            }
        }

        return $tokens;
    }
}
