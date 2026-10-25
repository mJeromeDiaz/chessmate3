<?php

declare(strict_types=1);

namespace App\Tests\Unit\MindMap;

use App\ApiResource\MindMap\Path;
use App\Enum\MindMap\Capacity;
use App\Enum\MindMap\StepKind;
use App\Enum\Training\Module;
use App\MindMap\Path\Challenges;
use App\MindMap\Path\Chapter;
use App\MindMap\Path\Paths;
use App\MindMap\Path\Step;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The défis' path as planned (docs/DEFIS.md): three fixed chapters of five tiers and a boss,
 * nothing locked, 10 passes; an opening chapter per repertoire, keyed by it, its boss paid once
 * per opening and side; its steps found without their activity when the repertoire is gone.
 */
final class ChallengesPathTest extends TestCase
{
    public function testTheFixedChaptersAndTheirBosses(): void
    {
        $chapters = Paths::chapters(Capacity::Challenges);

        self::assertSame(['mates', 'endgames', 'tactics'], array_map(static fn (Chapter $chapter): string => $chapter->key, $chapters));
        foreach ($chapters as $chapter) {
            self::assertTrue($chapter->free && $chapter->mastery, $chapter->key);
            self::assertCount(5, $chapter->sections[0]->steps);
            foreach ($chapter->sections[0]->steps as $step) {
                self::assertSame([StepKind::Exercise, 10, 0, 15, Module::ParcoursPuzzles, 10, true], [$step->kind, $step->passes, $step->crystals, $step->xp, $step->activity?->module, $step->activity?->count, $step->activity?->prepared]);
            }
        }
        $tier = $chapters[2]->sections[0]->steps[3];
        self::assertSame(['tactics-4', ['tier' => 3, 'themes' => ['discoveredAttack'], 'count' => 10]], [$tier->key, $tier->activity?->config]);
        $boss = $chapters[0]->boss;
        self::assertSame(['mates-boss', StepKind::Boss, 150, 60, 'defi-mates', null, 10, 3], [$boss->key, $boss->kind, $boss->crystals, $boss->xp, $boss->character, $boss->rewardKey, $boss->activity?->count, $boss->activity?->lives]);
        self::assertSame([['mateIn1'], ['mateIn1'], ['mateIn2'], ['mateIn2'], ['mateIn3'], ['mateIn3'], ['mateIn4'], ['mateIn4'], ['mateIn5'], ['mateIn5']], $boss->activity?->config['rounds'] ?? null);
    }

    public function testAnOpeningChapterAlternatesLinesAndPuzzles(): void
    {
        $id = Uuid::v7();
        $code = Challenges::code($id);
        $chapter = Challenges::opening($code, 4, ['repertoireId' => $id->toRfc4122(), 'family' => 'Sicilian_Defense', 'color' => 'b', 'lines' => 3, 'paid' => false], ['name' => 'Sicilienne']);

        self::assertSame(['op-'.$code, 4, false, ['name' => 'Sicilienne']], [$chapter->key, $chapter->number, $chapter->mastery, $chapter->meta]);
        self::assertSame(
            [Module::Repertoire, Module::ParcoursPuzzles, Module::Repertoire, Module::ParcoursPuzzles, Module::Repertoire],
            array_map(static fn (Step $step): ?Module => $step->activity?->module, $chapter->sections[0]->steps),
        );
        $lines = $chapter->sections[0]->steps[0]->activity;
        self::assertSame([['repertoireIds' => [$id->toRfc4122()], 'unit' => 'line', 'limit' => 3], 3], [$lines?->config, $lines?->count], 'as many lines as the repertoire has, at most 10');
        self::assertSame(['tier' => 3, 'family' => 'Sicilian_Defense', 'color' => 'b', 'count' => 10], $chapter->sections[0]->steps[3]->activity?->config);
        $boss = $chapter->boss;
        self::assertSame([150, 'opening:Sicilian_Defense:b', ['family' => 'Sicilian_Defense', 'color' => 'b', 'count' => 10, 'lives' => 3]], [$boss->crystals, $boss->rewardKey, $boss->activity?->config]);

        $paid = Challenges::opening($code, 5, ['repertoireId' => $id->toRfc4122(), 'family' => 'Sicilian_Defense', 'color' => 'b', 'lines' => null, 'paid' => true]);
        self::assertSame([0, 10], [$paid->boss->crystals, $paid->sections[0]->steps[0]->activity?->count]);
    }

    public function testAnOpeningKeyGivesItsRepertoireBack(): void
    {
        $id = Uuid::v7();
        $key = 'op-'.Challenges::code($id).'-boss';

        self::assertMatchesRegularExpression('/^'.Path::STEP_PATTERN.'$/', $key);
        self::assertLessThanOrEqual(48, \strlen('challenges:'.$key), 'training_run.path_step');
        self::assertTrue($id->equals(Challenges::parseOpening($key)['repertoireId'] ?? null));
        self::assertNull(Challenges::parseOpening('op-'.Challenges::code($id).'-6'));
        self::assertNull(Challenges::parseOpening('mates-1'));
        self::assertMatchesRegularExpression('/^('.Path::CAPACITY_PATTERN.')$/', Capacity::Challenges->value);

        // The repertoire gone: kind, passes and XP still known (the XP rebuild), nothing to play.
        $found = Paths::find(Capacity::Challenges, $key);
        self::assertSame([StepKind::Boss, 60, null], [$found['step']->kind ?? null, $found['step']->xp ?? null, $found['step']->activity ?? null]);
        self::assertSame(15, Paths::find(Capacity::Challenges, 'op-'.Challenges::code($id).'-2')['step']->xp ?? null);
        self::assertSame(Challenges::MASTERY_BADGE, Paths::masteryBadge(Capacity::Challenges));
    }
}
