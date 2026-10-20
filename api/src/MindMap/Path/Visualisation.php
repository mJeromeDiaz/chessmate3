<?php

declare(strict_types=1);

namespace App\MindMap\Path;

use App\Enum\MindMap\StepKind;
use App\Enum\Training\Module;

/**
 * The path of the visualisation capacity (docs/MIND_MAP.md, § 5; content written by the user,
 * docs/mindMap/Parcours Visualisation version 2.md): 7 chapters, their sections, bosses, chests
 * and rewards. A step without activity waits for its module (« bientôt »); the lots of the plan
 * give them one by one.
 */
final class Visualisation
{
    /** A counted lesson's clock: an expiry only (half an hour). */
    private const LESSON_SECONDS = 1_800;
    /** The positions of a recall lesson (chapter 2). */
    private const RECALL_COUNT = 5;

    /**
     * @return list<Chapter>
     */
    public static function chapters(): array
    {
        return [
            self::chapter1(),
            self::chapter2(),
            self::chapter3(),
            self::chapter4(),
            self::chapter5(),
            self::chapter6(),
            self::chapter7(),
        ];
    }

    /** Coordinates and notation: no section, a chest between 1.4 and 1.5. */
    private static function chapter1(): Chapter
    {
        return new Chapter('c1', 1, [
            new Section('1', null, [
                self::exercise('1-1', '1.1', 2, new Activity(Module::Coordinates, ['orientation' => 'white'], 60, 10)),
                self::exercise('1-2', '1.2', 3, new Activity(Module::Coordinates, ['orientation' => 'white', 'mode' => 'name'], 60, 10)),
                self::exercise('1-3', '1.3', 2, new Activity(Module::Coordinates, ['orientation' => 'white', 'mode' => 'color'], 60, 20)),
                self::exercise('1-4', '1.4', 3, self::notation('play', 10)),
                self::chest('1-chest', 60),
                self::exercise('1-5', '1.5', 3, self::notation('sequence', 5)),
                self::exercise('1-6', '1.6', 3, self::notation('write', 5)),
                self::exercise('1-7', '1.7', 3, self::notation('line', 5)),
                self::exercise('1-8', '1.8', 2, self::notation('game_write', 5)),
                self::exercise('1-9', '1.9', 2, self::notation('game_play', 5)),
            ]),
        ], self::boss('1-boss', 200, 100, 'jongleur', ['chapter_1', 'boss_jongleur'], self::jongleur(20)));
    }

    /**
     * Static blindfold vision: three sections of positions to remember, the levels 1 to 12 of the
     * position recall (docs/BLINDFOLD_RECALL.md); the mini-boss 2.1 rebuilds levels 2, 3 and 4 on a
     * board without coordinates, a life per piece wrong or missing; the mini-bosses 2.2 and 2.3 and
     * the hidden one ask the vision module's questions (docs/VISION.md); the Cyclope shows two puzzle
     * positions of 16 pieces (level 14) for 30 s each, a life per piece wrong or missing.
     */
    private static function chapter2(): Chapter
    {
        return new Chapter('c2', 2, [
            new Section('2-1', '2.1', self::exercises('2-1', '2.1', [2, 3, 3, 4], self::recalls(1)), [
                self::miniBoss('2-1-boss', 80, ['section_2_1'], null, 40, new Activity(
                    Module::BlindfoldRecall,
                    ['level' => 4, 'mode' => 'timed', 'count' => 3, 'lives' => PathRules::BOSS_LIVES, 'coordinates' => false, 'levels' => [2, 3, 4], 'distraction' => 'light'],
                    self::LESSON_SECONDS,
                    3,
                    PathRules::BOSS_LIVES,
                )),
            ]),
            new Section('2-2', '2.2', self::exercises('2-2', '2.2', [2, 3, 3, 4], self::recalls(5)), [
                self::miniBoss('2-2-boss', 100, ['section_2_2'], null, 40, self::vision('locate')),
            ]),
            new Section('2-3', '2.3', self::exercises('2-3', '2.3', [3, 3, 3, 4], self::recalls(9)), [
                self::miniBoss('2-3-boss', 120, ['section_2_3'], null, 40, self::vision('en_prise')),
            ], [
                self::hidden('2-3-hidden', 60, null, ['hidden_2_3'], self::vision('attackers')),
            ]),
        ], self::boss('2-boss', 240, 100, 'cyclope', ['chapter_2', 'boss_cyclope'], new Activity(
            Module::BlindfoldRecall,
            ['level' => 14, 'mode' => 'timed', 'count' => 2, 'lives' => PathRules::BOSS_LIVES, 'visibleSeconds' => 30, 'distraction' => 'light'],
            self::LESSON_SECONDS,
            2,
            PathRules::BOSS_LIVES,
        )));
    }

    /**
     * Dynamic vision: the Jongleur's short version first, then three sections; 3.1 follows a piece
     * (the vision module, docs/VISION.md, § 2 bis), its mini-boss asks three questions on each of 3
     * paths; 3.2 updates a position by moves (rebuilt, then questions), its mini-boss asks three
 * questions on each of 3 positions. Exercises calm, bosses with medium distractions.
     */
    private static function chapter3(): Chapter
    {
        return new Chapter('c3', 3, [
            new Section('3-0', null, [], [
                self::miniBoss('3-0-boss', 60, [], 'jongleur', 40, self::jongleur(10, 'medium')),
            ]),
            new Section('3-1', '3.1', self::exercises('3-1', '3.1', [3, 3, 3, 3, 4], array_map(
                static fn (string $kind): Activity => self::visionLesson($kind),
                ['track_capture', 'track_square', 'knight_distance', 'track_turns', 'track_long'],
            )), [
                self::miniBoss('3-1-boss', 100, ['section_3_1'], null, 40, new Activity(
                    Module::Vision,
                    ['kind' => 'track_boss', 'count' => 9, 'lives' => PathRules::BOSS_LIVES, 'distraction' => 'medium'],
                    self::LESSON_SECONDS,
                    9,
                    PathRules::BOSS_LIVES,
                )),
            ]),
            new Section('3-2', '3.2', self::exercises('3-2', '3.2', [3, 3, 3, 3, 4], [
                self::recallAfter(1),
                self::recallAfter(3),
                self::visionLesson('update_missing'),
                self::visionLesson('update_captures'),
                self::visionLesson('update_mixed'),
            ]), [
                self::miniBoss('3-2-boss', 120, ['section_3_2'], null, 40, new Activity(
                    Module::Vision,
                    ['kind' => 'update_boss', 'count' => 9, 'lives' => PathRules::BOSS_LIVES, 'distraction' => 'medium'],
                    self::LESSON_SECONDS,
                    9,
                    PathRules::BOSS_LIVES,
                )),
            ]),
            new Section('3-3', '3.3', self::exercises('3-3', '3.3', [3, 3, 3, 3, 4]), [
                self::miniBoss('3-3-boss', 140, ['section_3_3']),
            ]),
        ], self::boss('3-boss', 300, 120, 'gargouille', ['chapter_3', 'boss_gargouille']));
    }

    /** Calculation of variations: an exam closes each section, the Sablier hides in 4.2. */
    private static function chapter4(): Chapter
    {
        return new Chapter('c4', 4, [
            new Section('4-1', '4.1', self::exercises('4-1', '4.1', [3, 3, 4]), [
                self::miniBoss('4-1-boss', 100, ['section_4_1']),
            ]),
            new Section('4-2', '4.2', self::exercises('4-2', '4.2', [3, 3, 4]), [
                self::miniBoss('4-2-boss', 120, ['section_4_2']),
            ], [
                self::hidden('4-2-hidden', 80, 'sablier', ['boss_sablier']),
            ]),
            new Section('4-3', '4.3', self::exercises('4-3', '4.3', [3, 3, 3, 4]), [
                self::miniBoss('4-3-boss', 140, ['section_4_3']),
            ]),
            new Section('4-4', '4.4', self::exercises('4-4', '4.4', [3, 3, 4]), [
                self::miniBoss('4-4-boss', 160, ['section_4_4']),
            ]),
        ], self::boss('4-boss', 360, 150, 'cyclope', ['chapter_4', 'boss_cyclope_calcul']));
    }

    /** Refinement: dense positions, then blindfold play; the Fantôme closes the chapter. */
    private static function chapter5(): Chapter
    {
        return new Chapter('c5', 5, [
            new Section('5-1', '5.1', self::exercises('5-1', '5.1', [3, 3, 3, 4, 4]), [
                self::miniBoss('5-1-boss', 160, ['section_5_1'], null, 50),
            ], [
                self::hidden('5-1-hidden-1', 80, null, ['hidden_5_1_1']),
                self::hidden('5-1-hidden-2', 80, null, ['hidden_5_1_2']),
            ]),
            new Section('5-2', '5.2', self::exercises('5-2', '5.2', [3, 3, 4, 4]), [
                self::miniBoss('5-2-boss-1', 160, [], null, 50),
                self::miniBoss('5-2-boss-2', 180, ['section_5_2'], null, 50),
            ], [
                self::hidden('5-2-hidden', 100, 'flash', ['boss_flash']),
            ]),
        ], self::boss('5-boss', 440, 150, 'fantome', ['chapter_5', 'boss_fantome']));
    }

    /** Blindfold play: sequences of play, then the Caméléon. */
    private static function chapter6(): Chapter
    {
        return new Chapter('c6', 6, [
            new Section('6-1', '6.1', self::exercises('6-1', '6.1', [3, 4, 3])),
        ], self::boss('6-boss', 500, 200, 'cameleon', ['chapter_6', 'boss_cameleon']));
    }

    /** Bots and humans: a ladder of games, a chest between 7.2 and 7.3. */
    private static function chapter7(): Chapter
    {
        return new Chapter('c7', 7, [
            new Section('7', null, [
                self::exercise('7-1', '7.1', 2),
                self::exercise('7-2', '7.2', 2),
                self::chest('7-chest', 200),
                self::exercise('7-3', '7.3', 2),
                self::exercise('7-4', '7.4', 2),
                self::exercise('7-5', '7.5', 2),
            ]),
        ], self::boss('7-boss', 600, 250, 'sith', ['chapter_7', 'boss_sith', 'oeil_interieur']));
    }

    /**
     * The exercises of a section, numbered from 1, waiting for their modules.
     *
     * @param list<int>      $passes     the passes of each
     * @param list<Activity> $activities their activities, in order (none: waiting for their modules)
     *
     * @return list<Step>
     */
    private static function exercises(string $key, string $number, array $passes, array $activities = []): array
    {
        $steps = [];
        foreach ($passes as $i => $count) {
            $steps[] = self::exercise(\sprintf('%s-%d', $key, $i + 1), \sprintf('%s.%d', $number, $i + 1), $count, $activities[$i] ?? null);
        }

        return $steps;
    }

    /**
     * Four lessons of position recall from $from: RECALL_COUNT positions each, exact ones only right.
     *
     * @return list<Activity>
     */
    private static function recalls(int $from): array
    {
        return array_map(
            static fn (int $level): Activity => new Activity(Module::BlindfoldRecall, ['level' => $level, 'mode' => 'timed', 'count' => self::RECALL_COUNT], self::LESSON_SECONDS, self::RECALL_COUNT),
            range($from, $from + 3),
        );
    }

    /**
     * A lesson of the notation module (docs/NOTATION.md): $count items, its clock only an expiry.
     */
    private static function notation(string $kind, int $count): Activity
    {
        return new Activity(Module::Notation, ['kind' => $kind, 'count' => $count], self::LESSON_SECONDS, $count);
    }

    /**
     * A boss of the vision module (docs/VISION.md): 5 questions of $kind, 3 lives, its distractions.
     */
    private static function vision(string $kind, string $distraction = 'light'): Activity
    {
        return new Activity(Module::Vision, ['kind' => $kind, 'count' => 5, 'lives' => PathRules::BOSS_LIVES, 'distraction' => $distraction], self::LESSON_SECONDS, 5, PathRules::BOSS_LIVES);
    }

    /**
     * A lesson of position recall after moves (3.2.1, 3.2.2): a position of 6 pieces (level 4), $moves
     * moves of both sides announced, the position they reach to rebuild; 5 positions, exact ones only.
     */
    private static function recallAfter(int $moves): Activity
    {
        return new Activity(Module::BlindfoldRecall, ['level' => 4, 'mode' => 'timed', 'count' => self::RECALL_COUNT, 'announce' => $moves], self::LESSON_SECONDS, self::RECALL_COUNT);
    }

    /**
     * A lesson of the vision module: 5 questions of $kind at 80 %, calm.
     */
    private static function visionLesson(string $kind): Activity
    {
        return new Activity(Module::Vision, ['kind' => $kind, 'count' => 5], self::LESSON_SECONDS, 5);
    }

    /**
     * A fight against the Jongleur (docs/MIND_MAP.md, § 8): $count questions, 3 lives.
     */
    private static function jongleur(int $count, string $distraction = 'light'): Activity
    {
        return new Activity(Module::Jongleur, ['count' => $count, 'distraction' => $distraction], self::LESSON_SECONDS, $count, PathRules::BOSS_LIVES);
    }

    private static function exercise(string $key, string $number, int $passes, ?Activity $activity = null): Step
    {
        return new Step($key, $number, StepKind::Exercise, $passes, 0, PathRules::EXERCISE_XP, $activity);
    }

    private static function chest(string $key, int $crystals): Step
    {
        return new Step($key, null, StepKind::Chest, 1, $crystals, 0);
    }

    /**
     * @param list<string> $badges
     */
    private static function miniBoss(string $key, int $crystals, array $badges, ?string $character = null, int $xp = 40, ?Activity $activity = null): Step
    {
        return new Step($key, null, StepKind::MiniBoss, 1, $crystals, $xp, $activity, $character, $badges);
    }

    /**
     * @param list<string> $badges
     */
    private static function hidden(string $key, int $crystals, ?string $character, array $badges, ?Activity $activity = null): Step
    {
        return new Step($key, null, StepKind::HiddenBoss, 1, $crystals, 40, $activity, $character, $badges);
    }

    /**
     * @param list<string> $badges
     */
    private static function boss(string $key, int $crystals, int $xp, string $character, array $badges, ?Activity $activity = null): Step
    {
        return new Step($key, null, StepKind::Boss, 1, $crystals, $xp, $activity, $character, $badges);
    }
}
