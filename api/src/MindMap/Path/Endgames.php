<?php

declare(strict_types=1);

namespace App\MindMap\Path;

use App\Endgame\EndgameRules;
use App\Enum\MindMap\StepKind;
use App\Enum\Training\Module;

/**
 * The endgames path (docs/ENDGAMES.md, § 10; design « Parcours echiqueens », 2026-10-23): one
 * free chapter per mini-parcours, nothing ever locked. Each lesson of the catalogue is a step
 * whose key is the lesson's: a pass is a lesson of {@see self::LESSON_COUNT} positions at 80 %,
 * the first in learn mode (the textbook position, then variants, help allowed), the next ones in
 * review mode (variants, no help), Maia at the Elo proposed to the player. 2 passes: « known »;
 * {@see self::MASTERY_PASSES}: mastered. Each mini-parcours ends with a boss mixing its lessons
 * (perfect defence, 3 lives); the Roi des cendres, every endgame against a clock per position
 * ({@see \App\Endgame\Training\AshesDraw}), closes the path. Crystals come
 * from the bosses only.
 */
final class Endgames
{
    /** Passes of a lesson to master it (2 make it « known », the front's word). */
    public const MASTERY_PASSES = 10;
    /** The positions of a pass. */
    public const LESSON_COUNT = 5;
    /** The positions of a mini-parcours' boss. */
    public const BOSS_COUNT = 6;
    /** The positions of the Roi des cendres. */
    public const ASHES_COUNT = 10;
    /** The badge of every lesson mastered ({@see Paths::masteryBadge()}). */
    public const MASTERY_BADGE = 'endgames_mastered';
    /** A counted run's clock: an expiry only (half an hour). */
    private const LESSON_SECONDS = 1_800;
    /** XP of a mini-parcours' boss. */
    private const BOSS_XP = 60;

    /**
     * The mini-parcours in the page's order: key, lessons (catalogue keys), boss key, crystals,
     * character.
     *
     * @var list<array{key: string, lessons: list<string>, crystals: int, character: string}>
     */
    private const PATHS = [
        ['key' => 'mates', 'lessons' => ['kqk', 'krk', 'kbbk', 'kbnk'], 'crystals' => 120, 'character' => 'bourreau'],
        ['key' => 'pawns-basics', 'lessons' => ['square-promote', 'square-stop', 'opposition', 'key-squares'], 'crystals' => 150, 'character' => 'gardien'],
        ['key' => 'pawns-technique', 'lessons' => ['rook-pawn', 'pawn-defence', 'triangulation', 'pawn-race'], 'crystals' => 150, 'character' => 'horloger'],
        ['key' => 'queens', 'lessons' => ['queen-vs-pawn', 'queen-vs-bishop-pawn', 'queen-vs-rook'], 'crystals' => 120, 'character' => 'reine-noire'],
        ['key' => 'rooks', 'lessons' => ['king-cut-off', 'lucena', 'philidor', 'rook-vs-pawn', 'vancura'], 'crystals' => 180, 'character' => 'maitre-tours'],
        ['key' => 'bishops', 'lessons' => ['wrong-rook-pawn', 'bishop-vs-pawn', 'opposite-bishops', 'bishop-pawn'], 'crystals' => 150, 'character' => 'duelliste'],
        ['key' => 'knights', 'lessons' => ['knight-vs-pawn', 'knight-vs-rook-pawn', 'knight-pawn'], 'crystals' => 120, 'character' => 'cavalier-errant'],
    ];

    /**
     * @return list<Chapter>
     */
    public static function chapters(): array
    {
        $chapters = [];
        foreach (self::PATHS as $index => $path) {
            $steps = array_map(self::lesson(...), $path['lessons']);
            $chapters[] = new Chapter($path['key'], $index + 1, [new Section($path['key'], null, $steps)], new Step(
                $path['key'].'-boss',
                null,
                StepKind::Boss,
                1,
                $path['crystals'],
                self::BOSS_XP,
                self::boss($path['lessons']),
                $path['character'],
            ), true);
        }
        // The Roi des cendres: every endgame, one lesson of each mini-parcours at least.
        $chapters[] = new Chapter('ashes', \count(self::PATHS) + 1, [], new Step('ashes-boss', null, StepKind::Boss, 1, 600, 250, new Activity(
            Module::Endgames,
            [
                'mode' => 'review',
                'ashes' => array_map(static fn (array $path): array => $path['lessons'], self::PATHS),
                'elo' => EndgameRules::MAX_ELO,
                'count' => self::ASHES_COUNT,
                'lives' => PathRules::BOSS_LIVES,
            ],
            self::LESSON_SECONDS,
            self::ASHES_COUNT,
            PathRules::BOSS_LIVES,
        ), 'roi-cendres', ['boss_roi_cendres']), true);

        return $chapters;
    }

    /**
     * Every lesson of the path, in its order.
     *
     * @return list<string>
     */
    public static function lessonKeys(): array
    {
        return array_merge(...array_map(static fn (array $path): array => $path['lessons'], self::PATHS));
    }

    /**
     * A lesson's step: learn mode first (its textbook position, then variants), review mode next.
     */
    private static function lesson(string $key): Step
    {
        $config = ['lessons' => [$key], 'count' => self::LESSON_COUNT];

        return new Step($key, null, StepKind::Exercise, self::MASTERY_PASSES, 0, PathRules::EXERCISE_XP, new Activity(
            Module::Endgames,
            ['mode' => 'learn', 'variants' => self::LESSON_COUNT - 1, ...$config],
            self::LESSON_SECONDS,
            self::LESSON_COUNT,
            repeatConfig: ['mode' => 'review', ...$config],
        ));
    }

    /**
     * A mini-parcours' boss: variants of its lessons mixed, perfect defence, 3 lives.
     *
     * @param list<string> $lessons
     */
    private static function boss(array $lessons): Activity
    {
        return new Activity(
            Module::Endgames,
            ['mode' => 'review', 'lessons' => $lessons, 'elo' => EndgameRules::MAX_ELO, 'count' => self::BOSS_COUNT, 'lives' => PathRules::BOSS_LIVES],
            self::LESSON_SECONDS,
            self::BOSS_COUNT,
            PathRules::BOSS_LIVES,
        );
    }
}
