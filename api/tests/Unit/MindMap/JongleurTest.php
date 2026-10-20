<?php

declare(strict_types=1);

namespace App\Tests\Unit\MindMap;

use App\Enum\MindMap\BossQuestion;
use App\MindMap\Boss\JongleurRules;
use App\MindMap\Boss\QuestionDrawer;
use PHPUnit\Framework\TestCase;

/**
 * The Jongleur's questions drawn evenly, never the same square twice in a row, and their time
 * (docs/MIND_MAP.md, § 8).
 */
final class JongleurTest extends TestCase
{
    public function testEachKindComesAsOftenAsTheOthers(): void
    {
        $drawer = new QuestionDrawer();
        $asked = [];
        for ($i = 0; $i < 20; ++$i) {
            $asked[] = $drawer->question($asked);
        }
        $counts = array_count_values(array_map(static fn (BossQuestion $question): string => $question->value, $asked));
        self::assertSame(array_fill_keys(['color', 'find', 'name', 'play', 'write'], 4), self::sorted($counts));
    }

    public function testExcludedKindsAreNeverDrawn(): void
    {
        $drawer = new QuestionDrawer();
        for ($i = 0; $i < 30; ++$i) {
            self::assertTrue($drawer->question([], [BossQuestion::Play, BossQuestion::Write])->isSquare());
        }
    }

    public function testNeverTheSameSquareTwiceInARow(): void
    {
        $drawer = new QuestionDrawer();
        $previous = null;
        for ($i = 0; $i < 200; ++$i) {
            $square = $drawer->square($previous);
            self::assertMatchesRegularExpression('/^[a-h][1-8]$/', $square);
            self::assertNotSame($previous, $square);
            $previous = $square;
        }
    }

    public function testAnAnswerIsLateAfterItsTimeAndTheTolerances(): void
    {
        $start = new \DateTimeImmutable('2026-10-20 10:00:00.700');
        // Stored to the second: 10:00:00, then 6 s, 1 s, 2 s.
        self::assertFalse(JongleurRules::isLate($start, new \DateTimeImmutable('2026-10-20 10:00:09.000')));
        self::assertTrue(JongleurRules::isLate($start, new \DateTimeImmutable('2026-10-20 10:00:09.001')));
    }

    /**
     * @param array<string, int> $counts
     *
     * @return array<string, int>
     */
    private static function sorted(array $counts): array
    {
        ksort($counts);

        return $counts;
    }
}
