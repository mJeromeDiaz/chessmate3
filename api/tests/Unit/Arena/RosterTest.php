<?php

declare(strict_types=1);

namespace App\Tests\Unit\Arena;

use App\Arena\ArenaRules;
use App\Arena\Roster\Roster;
use App\Enum\Arena\Tier;
use App\Enum\MindMap\Capacity;
use PHPUnit\Framework\TestCase;

/**
 * The arena's bosses (docs/ARENA.md, § 2): every named boss of the paths, once.
 */
final class RosterTest extends TestCase
{
    public function testTheRosterHoldsEveryNamedBossOfThePathsOnce(): void
    {
        $characters = array_map(static fn ($boss): string => $boss->character, Roster::all());
        self::assertSame($characters, array_values(array_unique($characters)));
        $expected = [...Roster::pathCharacters(), Roster::OPENING_BOSS];
        sort($expected);
        $actual = $characters;
        sort($actual);
        self::assertSame($expected, $actual, 'a boss added to a path gets its place in the arena');
    }

    public function testEachBossIsFoundOnItsPath(): void
    {
        foreach (Roster::all() as $boss) {
            $steps = Roster::steps($boss);
            if (Roster::OPENING_BOSS === $boss->character) {
                self::assertSame([], $steps, 'the opening bosses are the player\'s chapters');
                continue;
            }
            self::assertNotSame([], $steps, $boss->character);
            self::assertGreaterThanOrEqual(600, $boss->elo);
            self::assertLessThanOrEqual(2600, max($boss->elo, $boss->endgameElo ?? 0), 'Maia stops at 2600');
        }
        $cyclope = Roster::find('cyclope');
        self::assertNotNull($cyclope);
        self::assertSame(['2-boss', '4-boss'], array_map(static fn ($step): string => $step->key, Roster::steps($cyclope)));
        self::assertSame([Capacity::Endgames, 1700], [Roster::find('bourreau')?->capacity, Roster::find('bourreau')?->endgameElo]);
        self::assertNull(Roster::find('dragon'));
    }

    public function testTheFirstDefeatPaysByTheBossRank(): void
    {
        self::assertSame([50, 100, 200], [ArenaRules::crystals(Tier::Hidden), ArenaRules::crystals(Tier::Chapter), ArenaRules::crystals(Tier::Final)]);
        self::assertSame(Tier::Final, Roster::find('sith')?->tier);
        self::assertSame(Tier::Hidden, Roster::find('flash')?->tier);
        self::assertSame([180, 2], ArenaRules::cadence('3+2'));
        self::assertNull(ArenaRules::cadence('1+0'));
        self::assertContains(ArenaRules::DEFAULT_CADENCE, ArenaRules::cadences());
    }
}
