<?php

declare(strict_types=1);

namespace App\Arena\Roster;

use App\Enum\Arena\Tier;
use App\Enum\MindMap\Capacity;
use App\MindMap\Path\Paths;
use App\MindMap\Path\Step;

/**
 * The bosses of the arena (docs/ARENA.md, § 2), in the page's order: every named boss of the paths
 * (docs/BOSSES.md), its strength and its style. Pure.
 */
final class Roster
{
    /** The défis' opening boss: one per repertoire, its steps not in the fixed chapters. */
    public const OPENING_BOSS = 'defi-opening';

    /** @var list<Boss>|null */
    private static ?array $bosses = null;

    /**
     * @return list<Boss>
     */
    public static function all(): array
    {
        return self::$bosses ??= [
            // Visualisation.
            new Boss('jongleur', Capacity::Visualisation, Tier::Chapter, 1000, null, ['varied']),
            new Boss('cyclope', Capacity::Visualisation, Tier::Chapter, 1300, null, ['solid']),
            new Boss('gargouille', Capacity::Visualisation, Tier::Chapter, 1500, null, ['aggressive']),
            new Boss('sablier', Capacity::Visualisation, Tier::Hidden, 1100, null, ['trades']),
            new Boss('flash', Capacity::Visualisation, Tier::Hidden, 1300, null, ['aggressive']),
            new Boss('fantome', Capacity::Visualisation, Tier::Chapter, 1700, null, ['solid']),
            new Boss('cameleon', Capacity::Visualisation, Tier::Chapter, 1900, null, ['varied']),
            new Boss('sith', Capacity::Visualisation, Tier::Final, 2600, null, ['solid'], true),
            // Calculation.
            new Boss('metronome', Capacity::Calculation, Tier::Chapter, 1200),
            new Boss('compteur', Capacity::Calculation, Tier::Chapter, 1600, null, ['forcing']),
            new Boss('avocat', Capacity::Calculation, Tier::Chapter, 2000, null, ['solid'], true),
            // Endgames: stronger once in an endgame.
            new Boss('bourreau', Capacity::Endgames, Tier::Chapter, 1200, 1700, ['king_hunter']),
            new Boss('gardien', Capacity::Endgames, Tier::Chapter, 1300, 1800, ['pawns']),
            new Boss('horloger', Capacity::Endgames, Tier::Chapter, 1450, 1950, ['trades', 'pawns']),
            new Boss('reine-noire', Capacity::Endgames, Tier::Chapter, 1650, 2150, ['queen_keeper', 'checks']),
            new Boss('maitre-tours', Capacity::Endgames, Tier::Chapter, 1800, 2300, ['rooks']),
            new Boss('duelliste', Capacity::Endgames, Tier::Chapter, 1900, 2300, ['bishops']),
            new Boss('cavalier-errant', Capacity::Endgames, Tier::Chapter, 2000, 2400, ['knights']),
            new Boss('roi-cendres', Capacity::Endgames, Tier::Final, 2400, 2600, ['trades'], true),
            // Défis.
            new Boss('defi-mates', Capacity::Challenges, Tier::Chapter, 1400, null, ['mate_finder', 'king_hunter']),
            new Boss('defi-endgames', Capacity::Challenges, Tier::Chapter, 1500, 2000, ['trades']),
            new Boss('defi-tactics', Capacity::Challenges, Tier::Chapter, 1600, null, ['checks', 'captures']),
            new Boss(self::OPENING_BOSS, Capacity::Challenges, Tier::Chapter, 1700, null, ['solid']),
        ];
    }

    public static function find(string $character): ?Boss
    {
        foreach (self::all() as $boss) {
            if ($boss->character === $character) {
                return $boss;
            }
        }

        return null;
    }

    /**
     * The steps of the fixed chapters where the boss is fought (the Cyclope: two; the opening boss:
     * none, its chapters are the player's).
     *
     * @return list<Step>
     */
    public static function steps(Boss $boss): array
    {
        $steps = [];
        foreach (Paths::chapters($boss->capacity) as $chapter) {
            foreach ($chapter->allSteps() as $step) {
                if ($step->character === $boss->character) {
                    $steps[] = $step;
                }
            }
        }

        return $steps;
    }

    /**
     * The characters of every boss of the fixed chapters, roster or not (a test keeps both lists
     * the same).
     *
     * @return list<string>
     */
    public static function pathCharacters(): array
    {
        $characters = [];
        foreach (Capacity::cases() as $capacity) {
            foreach (Paths::chapters($capacity) as $chapter) {
                foreach ($chapter->allSteps() as $step) {
                    if (null !== $step->character) {
                        $characters[$step->character] = true;
                    }
                }
            }
        }

        return array_keys($characters);
    }
}
