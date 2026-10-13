<?php

declare(strict_types=1);

namespace App\Repertoire\Auto;

/**
 * What the user plays, from their games: totals and one entry per opening family and color, most
 * played first (docs/REPERTOIRE.md, "Repertoire from my games"). Families played fewer than
 * {@see \App\Repertoire\Limits::$autoFamilyMinGames} times are left out: no line could come from them.
 *
 * @phpstan-type Family array{key: string, family: string, color: string, games: int, wins: int, draws: int, losses: int, analysed: int}
 * @phpstan-type SynthesisArray array{games: int, analysed: int, wins: int, draws: int, losses: int, families: list<Family>}
 */
final class Synthesis
{
    /**
     * @param list<GameRecord> $games
     *
     * @return SynthesisArray
     */
    public static function of(array $games, int $minGames): array
    {
        $totals = ['games' => 0, 'analysed' => 0, 'wins' => 0, 'draws' => 0, 'losses' => 0];
        /** @var array<string, Family> $families */
        $families = [];
        foreach ($games as $game) {
            $key = $game->familyKey();
            $families[$key] ??= ['key' => $key, 'family' => $game->family, 'color' => $game->color->value, 'games' => 0, 'wins' => 0, 'draws' => 0, 'losses' => 0, 'analysed' => 0];
            $outcome = match ($game->result) {
                1 => 'wins',
                0 => 'draws',
                default => 'losses',
            };
            ++$totals['games'];
            ++$totals[$outcome];
            ++$families[$key]['games'];
            ++$families[$key][$outcome];
            if ($game->analysed) {
                ++$totals['analysed'];
                ++$families[$key]['analysed'];
            }
        }
        $kept = array_values(array_filter($families, static fn (array $family): bool => $family['games'] >= $minGames));
        usort($kept, static fn (array $a, array $b): int => [$b['games'], $a['key']] <=> [$a['games'], $b['key']]);

        return [...$totals, 'families' => $kept];
    }
}
