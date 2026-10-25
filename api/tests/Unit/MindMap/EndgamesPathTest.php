<?php

declare(strict_types=1);

namespace App\Tests\Unit\MindMap;

use App\ApiResource\MindMap\Path;
use App\Endgame\EndgameRules;
use App\Endgame\Lesson\Catalogue;
use App\Endgame\Lesson\Lesson;
use App\Enum\MindMap\Capacity;
use App\Enum\MindMap\StepKind;
use App\Enum\MindMap\StepStatus;
use App\MindMap\Path\Chapter;
use App\MindMap\Path\Endgames;
use App\MindMap\Path\PathRules;
use App\MindMap\Path\Paths;
use App\MindMap\Path\PathState;
use App\MindMap\Path\Step;
use PHPUnit\Framework\TestCase;

/**
 * The endgames path as planned (docs/ENDGAMES.md, § 10): every lesson of the catalogue once, a step
 * keyed by its lesson, learn then review, 10 passes; a boss per mini-parcours mixing its lessons;
 * the Roi des cendres; nothing locked.
 */
final class EndgamesPathTest extends TestCase
{
    public function testEveryLessonOnceInItsMiniParcours(): void
    {
        $chapters = Paths::chapters(Capacity::Endgames);

        self::assertSame(
            ['mates', 'pawns-basics', 'pawns-technique', 'queens', 'rooks', 'bishops', 'knights', 'ashes'],
            array_map(static fn (Chapter $chapter): string => $chapter->key, $chapters),
        );
        $keys = array_map(static fn (Step $step): string => $step->key, array_merge(...array_map(static fn (Chapter $chapter): array => $chapter->sections[0]->steps ?? [], $chapters)));
        $catalogue = array_map(static fn (Lesson $lesson): string => $lesson->key, Catalogue::all());
        sort($catalogue);
        $sorted = $keys;
        sort($sorted);
        self::assertSame($catalogue, $sorted, 'Every lesson, once.');
        self::assertSame($keys, Endgames::lessonKeys());
        foreach ($chapters as $chapter) {
            self::assertTrue($chapter->free, $chapter->key);
        }
    }

    public function testKeysAreUniqueAndFitTheRoutes(): void
    {
        $keys = [];
        foreach (Paths::chapters(Capacity::Endgames) as $chapter) {
            foreach ($chapter->allSteps() as $step) {
                $keys[] = $step->key;
                self::assertMatchesRegularExpression('/^'.Path::STEP_PATTERN.'$/', $step->key);
            }
        }
        self::assertSame($keys, array_values(array_unique($keys)));
        self::assertMatchesRegularExpression('/^('.Path::CAPACITY_PATTERN.')$/', Capacity::Endgames->value);
    }

    public function testALessonIsLearntThenReviewed(): void
    {
        $step = (Paths::find(Capacity::Endgames, 'lucena') ?? self::fail('No lucena step.'))['step'];
        $activity = $step->activity ?? self::fail('Playable.');

        self::assertSame([StepKind::Exercise, 10, 0, PathRules::EXERCISE_XP], [$step->kind, $step->passes, $step->crystals, $step->xp]);
        // The net shown on the first two passes, offered from the third.
        self::assertSame(['mode' => 'learn', 'variants' => 4, 'net' => 'shown', 'lessons' => ['lucena'], 'count' => 5], $activity->configFor(0));
        self::assertSame(['mode' => 'review', 'net' => 'shown', 'lessons' => ['lucena'], 'count' => 5], $activity->configFor(1));
        self::assertSame(['mode' => 'review', 'net' => 'offered', 'lessons' => ['lucena'], 'count' => 5], $activity->configFor(2));
        self::assertSame($activity->configFor(2), $activity->configFor(9));
        self::assertSame([5, null], [$activity->count, $activity->lives]);
        self::assertTrue($activity->passes(5, 4), '80 %.');
        self::assertFalse($activity->passes(5, 3));
    }

    public function testTheBossesAndTheirRewards(): void
    {
        $bosses = [];
        foreach (Paths::chapters(Capacity::Endgames) as $chapter) {
            $boss = $chapter->boss;
            $bosses[$boss->key] = [$boss->character, $boss->crystals, $boss->xp];
            self::assertNotNull($boss->activity);
            if ('ashes-boss' === $boss->key) {
                continue;
            }
            $lessons = array_map(static fn (Step $step): string => $step->key, $chapter->sections[0]->steps);
            self::assertSame(['mode' => 'review', 'lessons' => $lessons, 'elo' => EndgameRules::MAX_ELO, 'count' => 6, 'lives' => 3], $boss->activity->config, $boss->key);
        }

        self::assertSame([
            'mates-boss' => ['bourreau', 120, 60],
            'pawns-basics-boss' => ['gardien', 150, 60],
            'pawns-technique-boss' => ['horloger', 150, 60],
            'queens-boss' => ['reine-noire', 120, 60],
            'rooks-boss' => ['maitre-tours', 180, 60],
            'bishops-boss' => ['duelliste', 150, 60],
            'knights-boss' => ['cavalier-errant', 120, 60],
            'ashes-boss' => ['roi-cendres', 600, 250],
        ], $bosses);
        self::assertSame(Endgames::MASTERY_BADGE, Paths::masteryBadge(Capacity::Endgames));
        self::assertNull(Paths::masteryBadge(Capacity::Visualisation));
    }

    public function testTheRoiDesCendresDrawsFromEveryMiniParcours(): void
    {
        $chapters = Paths::chapters(Capacity::Endgames);
        $ashes = array_pop($chapters);
        self::assertNotNull($ashes);
        $activity = $ashes->boss->activity;
        self::assertNotNull($activity);

        self::assertSame([
            'mode' => 'review',
            'ashes' => array_map(static fn (Chapter $chapter): array => array_map(static fn (Step $step): string => $step->key, $chapter->sections[0]->steps), $chapters),
            'elo' => EndgameRules::MAX_ELO,
            'count' => 10,
            'lives' => 3,
        ], $activity->config);
        self::assertSame([10, 3, ['boss_roi_cendres']], [$activity->count, $activity->lives, $ashes->boss->badges]);
        self::assertTrue($activity->passes(10, 8));
        self::assertFalse($activity->passes(10, 7), 'Its third position missed.');
        self::assertFalse($activity->passes(3, 0));
    }

    public function testNothingIsLocked(): void
    {
        $statuses = PathState::statuses(Paths::chapters(Capacity::Endgames), []);

        self::assertSame(['open'], array_values(array_unique(array_map(static fn (StepStatus $status): string => $status->value, $statuses))));
    }
}
