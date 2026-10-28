<?php

declare(strict_types=1);

namespace App\Tests\Unit\Arena;

use App\Arena\ArenaRules;
use App\Arena\Roster\Roster;
use App\Enum\Arena\Tier;
use App\Enum\MindMap\Capacity;
use PHPUnit\Framework\TestCase;

/**
 * The arena's bosses (docs/ARENA.md, § 2): every named boss of the paths, once, then the arena's
 * own; their levels and the prices of those sold.
 */
final class RosterTest extends TestCase
{
    public function testTheRosterHoldsEveryNamedBossOfThePathsOnce(): void
    {
        $characters = array_map(static fn ($boss): string => $boss->character, Roster::all());
        self::assertSame($characters, array_values(array_unique($characters)));
        $own = array_map(static fn ($boss): string => $boss->character, array_values(array_filter(Roster::all(), static fn ($boss): bool => null === $boss->capacity && Tier::Master !== $boss->tier)));
        self::assertSame(['zombie', 'requin', 'minotaure'], $own);
        $masters = array_map(static fn ($boss): string => $boss->character, array_values(array_filter(Roster::all(), static fn ($boss): bool => Tier::Master === $boss->tier)));
        self::assertSame(ArenaRules::MASTERS, $masters, 'the professors, last');
        self::assertSame($masters, \array_slice($characters, -4));
        $expected = [...Roster::pathCharacters(), Roster::OPENING_BOSS, ...$own, ...$masters];
        sort($expected);
        $actual = $characters;
        sort($actual);
        self::assertSame($expected, $actual, 'a boss added to a path gets its place in the arena');
    }

    public function testEachBossIsFoundOnItsPath(): void
    {
        foreach (Roster::all() as $boss) {
            $steps = Roster::steps($boss);
            self::assertGreaterThanOrEqual(600, $boss->elo);
            if (Tier::Master === $boss->tier) {
                self::assertTrue($boss->guarded, 'beyond Maia\'s 2600, Stockfish keeps a professor\'s strength');
                self::assertNull($boss->capacity);
            } else {
                self::assertLessThanOrEqual(2600, max($boss->elo, $boss->endgameElo ?? 0, $boss->openingElo ?? 0), 'Maia stops at 2600');
            }
            if (null === $boss->capacity || Roster::OPENING_BOSS === $boss->character) {
                self::assertSame([], $steps, 'the opening bosses are the player\'s chapters, the arena\'s own on no path');
                continue;
            }
            self::assertNotSame([], $steps, $boss->character);
            self::assertFalse($boss->forSale, 'a path\'s boss is never sold');
        }
        $cyclope = Roster::find('cyclope');
        self::assertNotNull($cyclope);
        self::assertSame(['2-boss', '4-boss'], array_map(static fn ($step): string => $step->key, Roster::steps($cyclope)));
        self::assertSame([Capacity::Endgames, 1700], [Roster::find('bourreau')?->capacity, Roster::find('bourreau')?->endgameElo]);
        self::assertNull(Roster::find('dragon'));
    }

    public function testTheFirstDefeatPaysByTheBossLevel(): void
    {
        self::assertSame([100, 200, 300, 450, 600, 1000], array_map(ArenaRules::crystals(...), [1, 2, 3, 4, 5, 6]));
        self::assertSame([100, 100, 600, 600], [Roster::find('zombie')?->crystals(), Roster::find('jongleur')?->crystals(), Roster::find('avocat')?->crystals(), Roster::find('roi-cendres')?->crystals()]);
        self::assertSame(Tier::Final, Roster::find('sith')?->tier);
        self::assertSame(Tier::Hidden, Roster::find('flash')?->tier);
        self::assertSame([180, 2], ArenaRules::cadence('3+2'));
        self::assertNull(ArenaRules::cadence('1+0'));
        self::assertContains(ArenaRules::DEFAULT_CADENCE, ArenaRules::cadences());
    }

    public function testTheLevelsGroupTheBossesByStrengthAndTheSoldOnesPayMoreThanTheyCost(): void
    {
        self::assertSame([1, 1, 2, 2, 3, 3, 4, 4, 5, 5], array_map(ArenaRules::level(...), [600, 1100, 1101, 1399, 1400, 1699, 1700, 1999, 2000, 2600]));
        self::assertSame([1, 1, 4], [Roster::find('zombie')?->level(), Roster::find('jongleur')?->level(), Roster::find('minotaure')?->level()]);
        foreach (ArenaRules::MASTERS as $master) {
            self::assertSame([6, null, 1000], [Roster::find($master)?->level(), Roster::find($master)?->price(), Roster::find($master)?->crystals()], 'a professor: the last level, whatever the Elo, never sold');
        }
        self::assertSame([2500, 2700, null], [Roster::find('lizy')?->elo, Roster::find('lizy')?->endgameElo, Roster::find('lizy')?->openingElo]);
        self::assertSame([2650, 2850], [Roster::find('aaron')?->elo, Roster::find('aaron')?->openingElo]);
        self::assertSame([2600, 2800], [Roster::find('albert')?->elo, Roster::find('ray')?->elo]);
        self::assertSame([null, null, 150, 300], [Roster::find('zombie')?->price(), Roster::find('jongleur')?->price(), Roster::find('requin')?->price(), Roster::find('minotaure')?->price()]);
        foreach (Roster::all() as $boss) {
            if (null !== $boss->price()) {
                self::assertLessThan($boss->crystals(), $boss->price(), 'a boss bought pays more than its price once beaten');
            }
        }
    }
}
