<?php

declare(strict_types=1);

namespace App\Coach\Diagnosis;

use App\Coach\CoachRules;
use App\Enum\Repertoire\Color;
use App\Repertoire\Auto\GameRecord;

/**
 * What the user's games say (docs/COACH.md): their rating, their most played openings by colour,
 * their endgames by kind with the advantages let slip ({@see CoachRules::lostAdvantage()}), and
 * the weakest kind (the most advantages let slip; at equal count, the fewest converted). Pure.
 *
 * @phpstan-type Opening array{family: string, games: int, wins: int, draws: int, losses: int}
 * @phpstan-type Endgame array{type: string, games: int, wins: int, draws: int, losses: int, advantages: int, lost: int}
 * @phpstan-type DiagnosisArray array{games: int, analysed: int, rating: int, white: list<Opening>, black: list<Opening>, endgames: array{games: int, advantages: int, lost: int, types: list<Endgame>}, weakest: Endgame|null}
 */
final class Diagnosis
{
    /** Openings listed per colour. */
    public const OPENINGS = 3;

    /**
     * @param list<GameRecord> $games
     *
     * @return DiagnosisArray
     */
    public static function of(array $games): array
    {
        $analysed = 0;
        $ratings = [];
        /** @var array{white: array<string, Opening>, black: array<string, Opening>} $openings */
        $openings = ['white' => [], 'black' => []];
        /** @var array<string, Endgame> $endgames */
        $endgames = [];
        $totals = ['games' => 0, 'advantages' => 0, 'lost' => 0];

        foreach ($games as $game) {
            $analysed += $game->analysed ? 1 : 0;
            if (null !== $game->rating) {
                $ratings[] = $game->rating;
            }
            $outcome = match ($game->result) {
                1 => 'wins',
                0 => 'draws',
                default => 'losses',
            };
            $color = Color::White === $game->color ? 'white' : 'black';
            $openings[$color][$game->family] ??= ['family' => $game->family, 'games' => 0, 'wins' => 0, 'draws' => 0, 'losses' => 0];
            ++$openings[$color][$game->family]['games'];
            ++$openings[$color][$game->family][$outcome];

            if (null === $game->endgameType) {
                continue;
            }
            $type = $game->endgameType->value;
            $endgames[$type] ??= ['type' => $type, 'games' => 0, 'wins' => 0, 'draws' => 0, 'losses' => 0, 'advantages' => 0, 'lost' => 0];
            ++$endgames[$type]['games'];
            ++$endgames[$type][$outcome];
            ++$totals['games'];
            if (true === CoachRules::advantage($game)) {
                ++$endgames[$type]['advantages'];
                ++$totals['advantages'];
            }
            if (CoachRules::lostAdvantage($game)) {
                ++$endgames[$type]['lost'];
                ++$totals['lost'];
            }
        }

        $types = array_values($endgames);
        // The weakest first: most advantages let slip, then the fewest converted, then the most played.
        usort($types, static fn (array $a, array $b): int => [$b['lost'], $a['advantages'] - $a['lost'], $b['games'], $a['type']] <=> [$a['lost'], $b['advantages'] - $b['lost'], $a['games'], $b['type']]);
        $weakest = null !== ($types[0] ?? null) && $types[0]['lost'] > 0 ? $types[0] : null;

        return [
            'games' => \count($games),
            'analysed' => $analysed,
            'rating' => self::median($ratings) ?? CoachRules::DEFAULT_RATING,
            'white' => self::top($openings['white']),
            'black' => self::top($openings['black']),
            'endgames' => [...$totals, 'types' => $types],
            'weakest' => $weakest,
        ];
    }

    /**
     * @param array<string, Opening> $openings
     *
     * @return list<Opening> most played first (then by name)
     */
    private static function top(array $openings): array
    {
        $list = array_values($openings);
        usort($list, static fn (array $a, array $b): int => [$b['games'], $a['family']] <=> [$a['games'], $b['family']]);

        return \array_slice($list, 0, self::OPENINGS);
    }

    /**
     * @param list<int> $values
     */
    private static function median(array $values): ?int
    {
        if ([] === $values) {
            return null;
        }
        sort($values);
        $middle = intdiv(\count($values), 2);

        return 0 === \count($values) % 2 ? intdiv($values[$middle - 1] + $values[$middle], 2) : $values[$middle];
    }
}
