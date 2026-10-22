<?php

declare(strict_types=1);

namespace App\Tests\Unit\MindMap;

use App\Enum\MindMap\StepKind;
use App\Enum\MindMap\StepStatus;
use App\Enum\Training\Module;
use App\MindMap\Path\Activity;
use App\MindMap\Path\Chapter;
use App\MindMap\Path\PathState;
use App\MindMap\Path\Section;
use App\MindMap\Path\Step;
use PHPUnit\Framework\TestCase;

/**
 * Where the player stands on a path (docs/MIND_MAP.md, § 3): exercises in order, a skip opens what
 * follows, mini-bosses open with their section (beating them clears it), hidden bosses, the
 * chapter's boss, the next chapter; chests earned by passes only; steps without module « soon ».
 *
 * @phpstan-import-type StepProgress from PathState
 */
final class PathStateTest extends TestCase
{
    public function testOnlyTheFirstExerciseAndTheChaptersBossAreOpenAtTheStart(): void
    {
        self::assertSame([
            'a1' => 'open', 'a2' => 'locked', 'a-chest' => 'locked', 'a3' => 'locked', 'a-boss' => 'open',
            'b1' => 'locked', 'b2' => 'locked', 'b-mini' => 'locked', 'b-hidden' => 'hidden', 'b-boss' => 'locked',
        ], self::statuses([]));
    }

    public function testExercisesOpenInOrderAndASkipOpensTheNext(): void
    {
        $statuses = self::statuses(['a1' => self::passes(1)]);
        self::assertSame(['open', 'locked'], [$statuses['a1'], $statuses['a2']], 'One pass of two: still open, the next locked.');

        $statuses = self::statuses(['a1' => self::done()]);
        self::assertSame(['done', 'soon'], [$statuses['a1'], $statuses['a2']], 'Without module, reachable but soon.');

        $statuses = self::statuses(['a1' => self::skipped(), 'a2' => self::skipped()]);
        self::assertSame(['skipped', 'skipped', 'locked', 'open', 'open'], [$statuses['a1'], $statuses['a2'], $statuses['a-chest'], $statuses['a3'], $statuses['a-boss']]);
    }

    public function testTheBossIsOpenWithItsChapterAndOpensTheNextChapter(): void
    {
        $chapterOne = ['a1' => self::done(), 'a2' => self::skipped(), 'a3' => self::done()];
        $statuses = self::statuses($chapterOne);
        self::assertSame(['open', 'locked'], [$statuses['a-boss'], $statuses['b1']]);

        $statuses = self::statuses([...$chapterOne, 'a-boss' => self::done()]);
        self::assertSame(['done', 'open', 'open'], [$statuses['a-boss'], $statuses['b1'], $statuses['b-mini']], 'The mini-boss is open with its section.');

        // A strong player beats the boss first: the next chapter opens, the skipped steps stay as they were.
        $statuses = self::statuses(['a-boss' => self::done()]);
        self::assertSame(['done', 'open', 'locked', 'open', 'soon'], [$statuses['a-boss'], $statuses['a1'], $statuses['a2'], $statuses['b1'], $statuses['b-boss']]);
    }

    public function testMiniBossThenHiddenBossThenTheChapterBoss(): void
    {
        $before = ['a1' => self::done(), 'a2' => self::done(), 'a3' => self::done(), 'a-boss' => self::done()];
        $statuses = self::statuses([...$before, 'b1' => self::done(), 'b2' => self::skipped()]);
        self::assertSame(['open', 'hidden', 'soon'], [$statuses['b-mini'], $statuses['b-hidden'], $statuses['b-boss']], 'The boss is reachable with its chapter, but has no module yet.');

        $statuses = self::statuses([...$before, 'b1' => self::done(), 'b2' => self::skipped(), 'b-mini' => self::done()]);
        self::assertSame(['done', 'open', 'soon'], [$statuses['b-mini'], $statuses['b-hidden'], $statuses['b-boss']], 'The hidden boss never blocks; the boss has no module yet.');
    }

    public function testAStrongPlayerBeatsTheMiniBossFirstAndClearsTheSection(): void
    {
        $progress = ['a-boss' => self::done(), 'b-mini' => self::done()];
        $statuses = self::statuses($progress);
        self::assertSame(['open', 'locked', 'done', 'open'], [$statuses['b1'], $statuses['b2'], $statuses['b-mini'], $statuses['b-hidden']], 'The exercises stay to play, in order; the hidden boss shows.');
        self::assertTrue(PathState::isCleared(self::chapters()[1]->sections[0], $progress));

        // Without its mini-boss beaten, the exercises alone do not clear a section that has one.
        self::assertFalse(PathState::isCleared(self::chapters()[1]->sections[0], ['b1' => self::done(), 'b2' => self::done()]));
        // A section without mini-boss: its exercises passed or skipped.
        self::assertTrue(PathState::isCleared(self::chapters()[0]->sections[0], ['a1' => self::done(), 'a2' => self::skipped(), 'a3' => self::done()]));
    }

    public function testAChestIsDueOncePassedNeverBySkips(): void
    {
        self::assertSame([], self::due(['a1' => self::done(), 'a2' => self::skipped()]));
        self::assertSame(['a-chest'], self::due(['a1' => self::done(), 'a2' => self::done()]));
        self::assertSame([], self::due(['a1' => self::done(), 'a2' => self::done(), 'a-chest' => self::done()]));
    }

    public function testActivitiesJudgeByRateOrByLives(): void
    {
        $exercise = new Activity(Module::Coordinates, [], 60, 10);
        self::assertTrue($exercise->passes(10, 8));
        self::assertFalse($exercise->passes(10, 7), 'Below 80 %.');
        self::assertFalse($exercise->passes(9, 9), 'Not enough items.');

        $boss = new Activity(Module::Coordinates, [], 60, 5, 3);
        self::assertTrue($boss->passes(7, 5), 'Two failures, a life left.');
        self::assertFalse($boss->passes(8, 5), 'Three failures.');
        self::assertFalse($boss->passes(4, 4));
    }

    /**
     * @param array<string, StepProgress> $progress
     *
     * @return array<string, string>
     */
    private static function statuses(array $progress): array
    {
        return array_map(static fn (StepStatus $status): string => $status->value, PathState::statuses(self::chapters(), $progress));
    }

    /**
     * @param array<string, StepProgress> $progress
     *
     * @return list<string>
     */
    private static function due(array $progress): array
    {
        return array_map(static fn (Step $step): string => $step->key, PathState::chestsDue(self::chapters(), $progress));
    }

    /**
     * Two chapters: a1 (playable), a2 (no module), a chest, a3 (playable), the boss; then a section
     * b1, b2 with a mini-boss and a hidden boss, and a boss without module.
     *
     * @return list<Chapter>
     */
    private static function chapters(): array
    {
        $activity = new Activity(Module::Coordinates, [], 60, 10);
        $boss = new Activity(Module::Coordinates, [], 60, 10, 3);

        return [
            new Chapter('a', 1, [
                new Section('a', null, [
                    new Step('a1', '1.1', StepKind::Exercise, 2, 0, 15, $activity),
                    new Step('a2', '1.2', StepKind::Exercise, 2, 0, 15),
                    new Step('a-chest', null, StepKind::Chest, 1, 60, 0),
                    new Step('a3', '1.3', StepKind::Exercise, 2, 0, 15, $activity),
                ]),
            ], new Step('a-boss', null, StepKind::Boss, 1, 200, 100, $boss)),
            new Chapter('b', 2, [
                new Section('b', '2.1', [
                    new Step('b1', '2.1.1', StepKind::Exercise, 2, 0, 15, $activity),
                    new Step('b2', '2.1.2', StepKind::Exercise, 2, 0, 15, $activity),
                ], [
                    new Step('b-mini', null, StepKind::MiniBoss, 1, 80, 40, $boss),
                ], [
                    new Step('b-hidden', null, StepKind::HiddenBoss, 1, 60, 40, $boss),
                ]),
            ], new Step('b-boss', null, StepKind::Boss, 1, 240, 100)),
        ];
    }

    /**
     * @return StepProgress
     */
    private static function passes(int $passes): array
    {
        return ['passes' => $passes, 'done' => false, 'skipped' => false];
    }

    /**
     * @return StepProgress
     */
    private static function done(): array
    {
        return ['passes' => 2, 'done' => true, 'skipped' => false];
    }

    /**
     * @return StepProgress
     */
    private static function skipped(): array
    {
        return ['passes' => 0, 'done' => false, 'skipped' => true];
    }
}
