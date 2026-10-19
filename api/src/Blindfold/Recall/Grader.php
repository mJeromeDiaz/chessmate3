<?php

declare(strict_types=1);

namespace App\Blindfold\Recall;

/**
 * Corrects a rebuild square by square (docs/BLINDFOLD_RECALL.md). Pure.
 */
final class Grader
{
    /**
     * @param array<string, string> $expected square => FEN piece letter, the position to rebuild
     * @param array<string, string> $placed   square => FEN piece letter, what the player placed
     */
    public static function grade(array $expected, array $placed): Grade
    {
        $correct = $wrong = $extra = 0;
        foreach ($placed as $square => $piece) {
            $target = $expected[$square] ?? null;
            if ($target === $piece) {
                ++$correct;
            } else {
                ++$wrong;
                if (null === $target) {
                    ++$extra;
                }
            }
        }
        $accuracy = RecallRules::accuracy($correct, \count($expected), $extra);

        return new Grade(
            expected: \count($expected),
            placed: \count($placed),
            correct: $correct,
            wrong: $wrong,
            extra: $extra,
            missing: \count($expected) - $correct,
            accuracy: $accuracy,
            status: RecallRules::status($correct, \count($expected), \count($placed), $accuracy),
        );
    }
}
