<?php

declare(strict_types=1);

namespace App\MindMap\Path;

use App\Enum\MindMap\StepKind;
use App\Enum\Training\Module;

/**
 * The path of the calculation capacity (docs/MIND_MAP.md, § 5 bis; content written by the user,
 * docs/mindMap/profondeur_de_calcul.md): its first 3 chapters, the others later. A line is
 * calculated on a frozen board, then played whole, both sides (docs/CALCULATION.md). A step
 * without activity waits for its module (« bientôt »); the lots of the plan give them one by one.
 */
final class Calculation
{
    /** A counted lesson's clock: an expiry only (half an hour). */
    private const LESSON_SECONDS = 1_800;
    /** The lines of a lesson. */
    private const LESSON_COUNT = 10;
    /** 3.2: positions of two lines each. */
    private const TWO_REPLIES_COUNT = 6;
    /** The Avocat du diable's positions. */
    private const ADVOCATE_COUNT = 5;

    /**
     * @return list<Chapter>
     */
    public static function chapters(): array
    {
        return [
            self::chapter1(),
            self::chapter2(),
            self::chapter3(),
        ];
    }

    /**
     * The straight line: mates in 1, 2, 2 with a capture, then 3, a chest after 1.2; the mini-boss,
     * 4 mates in 3 with 2 lives; the Métronome, 12 lines right in a row with 3 lives.
     */
    private static function chapter1(): Chapter
    {
        return new Chapter('c1', 1, [
            new Section('1', null, [
                self::exercise('1-1', '1.1', 2, self::lesson(1)),
                self::exercise('1-2', '1.2', 2, self::lesson(2)),
                self::chest('1-chest', 60),
                self::exercise('1-3', '1.3', 2, self::lesson(2, true)),
                self::exercise('1-4', '1.4', 2, self::lesson(3)),
            ], [
                self::miniBoss('1-mini', 80, new Activity(Module::Calculation, ['mate' => 3, 'count' => 4, 'lives' => 2], self::LESSON_SECONDS, 4, 2)),
            ]),
        ], self::boss('1-boss', 200, 100, 'metronome', ['chapter_1', 'boss_metronome'], new Activity(
            Module::Calculation,
            ['streak' => 12, 'lives' => PathRules::BOSS_LIVES],
            self::LESSON_SECONDS,
            12,
            PathRules::BOSS_LIVES,
        )));
    }

    /**
     * Going the distance: lines of 3 moves that win material, mates in 3, lines of 4 moves; a chest
     * after 2.4; a piece given up, a piece coming into play (3 or 4 moves); then a question on the
     * position the line reaches (2.7, 2.8); the mini-boss, solved then replayed; the Compteur, 3
     * positions of a long line announced, 3 questions on each (a vision boss).
     */
    private static function chapter2(): Chapter
    {
        return new Chapter('c2', 2, [
            new Section('2', null, [
                self::exercise('2-1', '2.1', 2, self::gain(3)),
                self::exercise('2-2', '2.2', 2, self::gain(3)),
                self::exercise('2-3', '2.3', 2, self::lesson(3)),
                self::exercise('2-4', '2.4', 2, self::gain(4)),
                self::chest('2-chest', 80),
                self::exercise('2-5', '2.5', 2, self::themed(['sacrifice'])),
                self::exercise('2-6', '2.6', 2, self::themed(['quietMove', 'clearance'])),
                self::exercise('2-7', '2.7', 2, self::askedAfter(3)),
                self::exercise('2-8', '2.8', 2, self::askedAfter(4)),
            ], [
                self::miniBoss('2-mini', 100, new Activity(
                    Module::Calculation,
                    ['moves' => [3, 4], 'solve' => true, 'count' => 4, 'lives' => PathRules::BOSS_LIVES],
                    self::LESSON_SECONDS,
                    4,
                    PathRules::BOSS_LIVES,
                )),
            ]),
        ], self::boss('2-boss', 240, 100, 'compteur', ['chapter_2', 'boss_compteur'], new Activity(
            Module::Vision,
            ['kind' => 'counter', 'count' => 9, 'lives' => PathRules::BOSS_LIVES, 'questionSeconds' => 15],
            self::LESSON_SECONDS,
            9,
            PathRules::BOSS_LIVES,
        )));
    }

    /**
     * The opponent has a say, judged by Stockfish in the browser (docs/CALCULATION.md, § 2 quater):
     * the best of two replies, a line against each of two replies, a mate threat parried, a check
     * in reply; the mini-boss, a combination among three forcing moves; the Avocat du diable, the
     * player's own move then its best refutation, 5 positions with 3 lives.
     */
    private static function chapter3(): Chapter
    {
        return new Chapter('c3', 3, [
            new Section('3', null, [
                self::exercise('3-1', '3.1', 2, self::judged('reply', [2, 3], self::LESSON_COUNT)),
                self::exercise('3-2', '3.2', 2, self::judged('two_replies', [2, 3], self::TWO_REPLIES_COUNT)),
                self::exercise('3-3', '3.3', 2, self::judged('defence', [1, 2], self::LESSON_COUNT)),
                self::exercise('3-4', '3.4', 2, self::judged('intermezzo', [2, 3], self::LESSON_COUNT)),
            ], [
                self::miniBoss('3-mini', 120, new Activity(
                    Module::Calculation,
                    ['kind' => 'combo', 'moves' => [2, 3], 'count' => 4, 'lives' => PathRules::BOSS_LIVES],
                    self::LESSON_SECONDS,
                    4,
                    PathRules::BOSS_LIVES,
                )),
            ]),
        ], self::boss('3-boss', 300, 120, 'avocat', ['chapter_3', 'boss_avocat'], new Activity(
            Module::Calculation,
            ['kind' => 'advocate', 'moves' => [2, 3], 'count' => self::ADVOCATE_COUNT, 'lives' => PathRules::BOSS_LIVES],
            self::LESSON_SECONDS,
            self::ADVOCATE_COUNT,
            PathRules::BOSS_LIVES,
        )));
    }

    /**
     * A lesson of the calculation module (docs/CALCULATION.md): LESSON_COUNT mates in $mate (with a
     * capture on the way), at 80 %.
     */
    private static function lesson(int $mate, bool $capture = false): Activity
    {
        $config = ['mate' => $mate, 'count' => self::LESSON_COUNT];
        if ($capture) {
            $config['capture'] = true;
        }

        return new Activity(Module::Calculation, $config, self::LESSON_SECONDS, self::LESSON_COUNT);
    }

    /**
     * A lesson of LESSON_COUNT lines of $moves moves that win material (no mate), played exactly.
     */
    private static function gain(int $moves): Activity
    {
        return new Activity(Module::Calculation, ['moves' => $moves, 'ending' => 'material', 'count' => self::LESSON_COUNT], self::LESSON_SECONDS, self::LESSON_COUNT);
    }

    /**
     * A lesson of LESSON_COUNT lines of 3 or 4 moves of Lichess $themes (OR), however they end.
     *
     * @param list<string> $themes
     */
    private static function themed(array $themes): Activity
    {
        return new Activity(Module::Calculation, ['moves' => [3, 4], 'themes' => $themes, 'count' => self::LESSON_COUNT], self::LESSON_SECONDS, self::LESSON_COUNT);
    }

    /**
     * A lesson of chapter 3, of $kind, judged by the engine in the browser: $count lines of $moves
     * moves, however they end.
     *
     * @param list<int> $moves
     */
    private static function judged(string $kind, array $moves, int $count): Activity
    {
        return new Activity(Module::Calculation, ['kind' => $kind, 'moves' => $moves, 'count' => $count], self::LESSON_SECONDS, $count);
    }

    /**
     * A lesson of 5 positions: a line of $moves moves, however it ends, then a question on the
     * position its solution reaches (LESSON_COUNT items).
     */
    private static function askedAfter(int $moves): Activity
    {
        return new Activity(Module::Calculation, ['moves' => $moves, 'question' => true, 'count' => self::LESSON_COUNT], self::LESSON_SECONDS, self::LESSON_COUNT);
    }

    private static function exercise(string $key, string $number, int $passes, ?Activity $activity = null): Step
    {
        return new Step($key, $number, StepKind::Exercise, $passes, 0, PathRules::EXERCISE_XP, $activity);
    }

    private static function chest(string $key, int $crystals): Step
    {
        return new Step($key, null, StepKind::Chest, 1, $crystals, 0);
    }

    private static function miniBoss(string $key, int $crystals, ?Activity $activity = null): Step
    {
        return new Step($key, null, StepKind::MiniBoss, 1, $crystals, 40, $activity);
    }

    /**
     * @param list<string> $badges
     */
    private static function boss(string $key, int $crystals, int $xp, string $character, array $badges, ?Activity $activity = null): Step
    {
        return new Step($key, null, StepKind::Boss, 1, $crystals, $xp, $activity, $character, $badges);
    }
}
