<?php

declare(strict_types=1);

namespace App\Endgame\Training;

use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * The lessons of a Roi des cendres' fight (docs/ENDGAMES.md, § 10): one lesson of each group
 * (the mini-parcours), then others at random up to $count, all distinct, in the groups' order.
 * Seeded by the run: the same draw at each position and after a reload, nothing stored. Pure.
 */
final class AshesDraw
{
    /**
     * @param list<list<string>> $groups lesson keys, in the page's order
     *
     * @return list<string>
     */
    public static function lessons(array $groups, int $count, string $seed): array
    {
        $randomizer = new Randomizer(new Xoshiro256StarStar(hash('sha256', $seed, true)));
        $order = array_values(array_unique(array_merge(...$groups)));
        $chosen = [];
        foreach (\array_slice($randomizer->shuffleArray(array_keys($groups)), 0, $count) as $group) {
            $left = array_values(array_diff($groups[$group], $chosen));
            if ([] !== $left) {
                $chosen[] = $left[$randomizer->getInt(0, \count($left) - 1)];
            }
        }
        $others = $randomizer->shuffleArray(array_values(array_diff($order, $chosen)));
        $chosen = [...$chosen, ...\array_slice($others, 0, max(0, $count - \count($chosen)))];

        return array_values(array_intersect($order, $chosen));
    }
}
