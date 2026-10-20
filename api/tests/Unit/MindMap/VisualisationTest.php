<?php

declare(strict_types=1);

namespace App\Tests\Unit\MindMap;

use App\ApiResource\MindMap\Path;
use App\Enum\MindMap\Capacity;
use App\Enum\MindMap\StepKind;
use App\MindMap\Path\Chapter;
use App\MindMap\Path\PathRules;
use App\MindMap\Path\Paths;
use App\MindMap\Path\Section;
use App\MindMap\Path\Step;
use PHPUnit\Framework\TestCase;

/**
 * The visualisation path as written (docs/MIND_MAP.md, § 5): 7 chapters, keys unique and fit for
 * URIs, 2 to 4 passes per exercise, one per boss, the rewards of the table.
 */
final class VisualisationTest extends TestCase
{
    public function testItsShape(): void
    {
        $chapters = Paths::chapters(Capacity::Visualisation);

        self::assertSame([1, 2, 3, 4, 5, 6, 7], array_map(static fn (Chapter $chapter): int => $chapter->number, $chapters));
        self::assertSame(['jongleur', 'cyclope', 'gargouille', 'cyclope', 'fantome', 'cameleon', 'sith'], array_map(static fn (Chapter $chapter): ?string => $chapter->boss->character, $chapters));
        self::assertSame(['1-1', '1-2', '1-3', '1-4', '1-chest', '1-5'], \array_slice(array_map(static fn (Step $step): string => $step->key, $chapters[0]->sections[0]->steps), 0, 6), 'The chest between 1.4 and 1.5.');
        self::assertSame(['2.1', '2.2', '2.3'], array_map(static fn (Section $section): ?string => $section->number, $chapters[1]->sections));
    }

    public function testKeysAreUniqueAndFitTheRoutes(): void
    {
        $keys = [];
        foreach (Paths::chapters(Capacity::Visualisation) as $chapter) {
            foreach ($chapter->allSteps() as $step) {
                $keys[] = $step->key;
                self::assertMatchesRegularExpression('/^'.Path::STEP_PATTERN.'$/', $step->key);
            }
        }
        self::assertSame($keys, array_values(array_unique($keys)));
        self::assertSame(implode('|', Capacity::values()), Path::CAPACITY_PATTERN, 'The route takes every capacity.');
    }

    public function testPassesAndRewards(): void
    {
        $crystals = [];
        foreach (Paths::chapters(Capacity::Visualisation) as $chapter) {
            foreach ($chapter->allSteps() as $step) {
                if (StepKind::Exercise === $step->kind) {
                    self::assertGreaterThanOrEqual(2, $step->passes, $step->key);
                    self::assertLessThanOrEqual(4, $step->passes, $step->key);
                    self::assertSame([0, PathRules::EXERCISE_XP], [$step->crystals, $step->xp], $step->key);
                    continue;
                }
                self::assertSame(1, $step->passes, $step->key);
                self::assertGreaterThan(0, $step->crystals, $step->key);
                if ($step->kind->isBoss()) {
                    self::assertTrue(null === $step->activity || null !== $step->activity->lives, $step->key.': a boss is judged by its lives.');
                }
                $crystals[$step->key] = $step->crystals;
            }
        }
        self::assertSame(
            ['1-chest' => 60, '1-boss' => 200, '2-1-boss' => 80, '2-2-boss' => 100, '2-3-boss' => 120, '2-3-hidden' => 60, '2-boss' => 240],
            \array_slice($crystals, 0, 7),
        );
        self::assertSame(600, $crystals['7-boss']);
    }

    public function testTheFirstExerciseIsTheCoordinates(): void
    {
        $step = Paths::find(Capacity::Visualisation, '1-1')['step'] ?? null;

        self::assertNotNull($step?->activity);
        self::assertSame(['coordinates', 60, 10, null], [$step->activity->module->value, $step->activity->budgetSeconds, $step->activity->count, $step->activity->lives]);
        self::assertNull(Paths::find(Capacity::Visualisation, 'nope'));
    }
}
