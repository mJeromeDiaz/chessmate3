<?php

declare(strict_types=1);

namespace App\Tests\Unit\Arena;

use App\Arena\Roster\ArenaReader;
use App\Arena\Roster\Roster;
use App\Enum\Activity\ExerciseType;
use App\Gamification\Xp\XpRules;
use PHPUnit\Framework\TestCase;

/**
 * What a game of the arena gains (docs/ARENA.md, § 6): XP by its result, a short one nothing; the
 * badges of the bosses beaten.
 */
final class ArenaXpTest extends TestCase
{
    public function testAGameGainsByItsResultAndLength(): void
    {
        $xp = static fn (string $result, int $plies): int => XpRules::exercise(ExerciseType::ArenaGame, 'won' === $result, 600_000, 1, ['result' => $result, 'plies' => $plies]);
        self::assertSame(30, $xp('won', 7), 'a win, however short');
        self::assertSame([0, 15], [$xp('drawn', 39), $xp('drawn', 40)]);
        self::assertSame([0, 5], [$xp('lost', 19), $xp('lost', 20)]);
        self::assertSame(0, $xp('aborted', 80));
        self::assertNull(XpRules::module(ExerciseType::ArenaGame), 'not a module of the timed runs');
        self::assertSame('arena', XpRules::ledgerModule(ExerciseType::ArenaGame));
        self::assertSame('puzzles', XpRules::ledgerModule(ExerciseType::PuzzleRated));
    }

    public function testBadgesCountTheBossesBeaten(): void
    {
        $at = static fn (int $day): \DateTimeImmutable => new \DateTimeImmutable(\sprintf('2026-10-%02d 12:00:00', $day), new \DateTimeZone('UTC'));
        $record = static fn (?\DateTimeImmutable $won): array => ['won' => null === $won ? 0 : 1, 'drawn' => 0, 'lost' => 1, 'firstWonAt' => $won, 'paid' => null !== $won];
        self::assertSame([], ArenaReader::badges(['jongleur' => $record(null)]));

        $records = [];
        foreach (Roster::all() as $index => $boss) {
            if ($index < 10) {
                $records[$boss->character] = $record($at(10 + $index));
            }
        }
        self::assertSame([
            ['badge' => 'arena_first', 'earnedAt' => '2026-10-10T12:00:00+00:00'],
            ['badge' => 'arena_ten', 'earnedAt' => '2026-10-19T12:00:00+00:00'],
        ], ArenaReader::badges($records));

        foreach (Roster::all() as $index => $boss) {
            $records[$boss->character] ??= $record($at(min(28, 10 + $index)));
        }
        self::assertSame('arena_all', ArenaReader::badges($records)[2]['badge'] ?? null);
    }
}
