<?php

declare(strict_types=1);

namespace App\Brilliancy;

use App\Chess\Attacks;
use App\Chess\Sacrifice;
use App\Enum\Brilliancy\Kind;

/**
 * The rules of « Brillant ou Blunder » (docs/BRILLIANCY.md), pure: what a puzzle of the catalogue
 * gives (a brilliant move, a blunder, both or neither), the run lengths, the draw's windows.
 */
final class BrilliancyRules
{
    public const RUN_MINUTES = [1, 2, 5, 10];
    public const DEFAULT_MINUTES = 2;

    /** The draw's windows around the player's puzzle rating, then any rating. */
    public const MARGINS = [300, 600, 4000];
    public const CANDIDATES = 20;
    public const DRAWS = 3;

    /** Themes whose sacrifice is too plain a find to be shown as brilliant. */
    public const TOO_PLAIN = ['mateIn1', 'oneMove'];

    /**
     * @return list<int> in seconds
     */
    public static function budgets(): array
    {
        return array_map(static fn (int $minutes): int => $minutes * 60, self::RUN_MINUTES);
    }

    public static function allows(int $seconds): bool
    {
        return \in_array($seconds, self::budgets(), true);
    }

    /**
     * What a puzzle gives: a blunder when the opponent's first move offers material (the puzzle
     * punishes it), a brilliant move when the player's answer does (it wins), unless the puzzle is
     * too plain.
     *
     * @param list<string> $moves  the puzzle's, UCI
     * @param list<string> $themes
     *
     * @return list<Kind>
     */
    public static function kinds(string $fen, array $moves, array $themes): array
    {
        if (\count($moves) < 2) {
            return [];
        }
        $kinds = [];
        try {
            $board = Attacks::board($fen);
            if (Sacrifice::offers($board, $moves[0])) {
                $kinds[] = Kind::Blunder;
            }
            if ([] === array_intersect($themes, self::TOO_PLAIN) && Sacrifice::offers(Sacrifice::play($board, $moves[0]), $moves[1])) {
                $kinds[] = Kind::Brilliant;
            }
        } catch (\InvalidArgumentException) {
            return [];
        }

        return $kinds;
    }

    /**
     * The rating windows of the draw around $target.
     *
     * @return list<array{int, int}>
     */
    public static function ranges(int $target): array
    {
        return array_map(static fn (int $margin): array => [max(0, $target - $margin), $target + $margin], self::MARGINS);
    }

    /**
     * The rules as the front reads them.
     *
     * @return array{runMinutes: list<int>, defaultMinutes: int}
     */
    public static function toArray(): array
    {
        return [
            'runMinutes' => self::RUN_MINUTES,
            'defaultMinutes' => self::DEFAULT_MINUTES,
        ];
    }
}
