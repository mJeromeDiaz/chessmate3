<?php

declare(strict_types=1);

namespace App\Tests\Unit\MindMap;

use App\ApiResource\MindMap\Path;
use App\Enum\MindMap\Capacity;
use App\Enum\MindMap\StepKind;
use App\MindMap\Path\Chapter;
use App\MindMap\Path\PathRules;
use App\MindMap\Path\Paths;
use App\MindMap\Path\Step;
use PHPUnit\Framework\TestCase;

/**
 * The calculation path as written (docs/MIND_MAP.md, § 5 bis): its first 3 chapters, keys unique
 * and fit for URIs, the bosses and rewards of the plan.
 */
final class CalculationTest extends TestCase
{
    public function testItsShape(): void
    {
        $chapters = Paths::chapters(Capacity::Calculation);

        self::assertSame([1, 2, 3], array_map(static fn (Chapter $chapter): int => $chapter->number, $chapters));
        self::assertSame(['metronome', 'compteur', 'avocat'], array_map(static fn (Chapter $chapter): ?string => $chapter->boss->character, $chapters));
        self::assertSame(['1-1', '1-2', '1-chest', '1-3', '1-4'], array_map(static fn (Step $step): string => $step->key, $chapters[0]->sections[0]->steps), 'The chest after 1.2.');
        self::assertSame(['1-mini'], array_map(static fn (Step $step): string => $step->key, $chapters[0]->sections[0]->miniBosses));
    }

    public function testKeysAreUniqueAndFitTheRoutes(): void
    {
        $keys = [];
        foreach (Paths::chapters(Capacity::Calculation) as $chapter) {
            foreach ($chapter->allSteps() as $step) {
                $keys[] = $step->key;
                self::assertMatchesRegularExpression('/^'.Path::STEP_PATTERN.'$/', $step->key);
            }
        }
        self::assertSame($keys, array_values(array_unique($keys)));
        self::assertMatchesRegularExpression('/^('.Path::CAPACITY_PATTERN.')$/', Capacity::Calculation->value);
    }

    public function testPassesAndRewards(): void
    {
        $crystals = [];
        foreach (Paths::chapters(Capacity::Calculation) as $chapter) {
            foreach ($chapter->allSteps() as $step) {
                if (StepKind::Exercise === $step->kind) {
                    self::assertSame([2, 0, PathRules::EXERCISE_XP], [$step->passes, $step->crystals, $step->xp], $step->key);
                    continue;
                }
                $crystals[$step->key] = $step->crystals;
            }
        }
        self::assertSame(
            ['1-chest' => 60, '1-mini' => 80, '1-boss' => 200, '2-chest' => 80, '2-mini' => 100, '2-boss' => 240, '3-mini' => 120, '3-boss' => 300],
            $crystals,
        );
        self::assertSame(['chapter_1', 'boss_metronome'], (Paths::find(Capacity::Calculation, '1-boss')['step'] ?? null)?->badges);
    }
}
