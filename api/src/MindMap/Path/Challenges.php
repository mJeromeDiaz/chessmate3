<?php

declare(strict_types=1);

namespace App\MindMap\Path;

use App\Enum\MindMap\StepKind;
use App\Enum\Parcours\Theme;
use App\Enum\Repertoire\TestUnit;
use App\Enum\Training\Module;
use App\Parcours\ParcoursRules;
use Symfony\Component\Uid\Uuid;

/**
 * The défis' path (docs/DEFIS.md, choices of 2026-10-25), Lizy's: one free chapter per theme
 * (mates, endgames, tactics), then one per repertoire of the player that has an opening
 * ({@see \App\Challenge\Path\OpeningChapters}); nothing ever locked. A step is a tier of the
 * former parcours: a pass is {@see self::PASS_COUNT} puzzles of its themes at 80 %, below, at and
 * above the player's rating (an opening chapter's lines tiers: as many lines of its repertoire);
 * 2 passes: « known », {@see self::MASTERY_PASSES}: mastered. Each chapter ends with a boss: its
 * tiers mixed, two puzzles each in order, above the player's rating, no help, 3 lives. Crystals
 * come from the bosses only, an opening's once per opening and side.
 */
final class Challenges
{
    /** Passes of a step to master it (2 make it « known »). */
    public const MASTERY_PASSES = 10;
    /** The puzzles (or lines) of a pass. */
    public const PASS_COUNT = 10;
    /** The puzzles of a boss: two per tier. */
    public const BOSS_COUNT = 10;
    /** The badge of the fixed chapters' steps all mastered ({@see Paths::masteryBadge()}). */
    public const MASTERY_BADGE = 'challenges_mastered';
    /** An opening chapter's key: the prefix, then its repertoire's id in lower-case base32. */
    public const OPENING_PREFIX = 'op-';
    /** A boss's crystals and XP. */
    private const BOSS_CRYSTALS = 150;
    private const BOSS_XP = 60;
    /** A counted run's clock: an expiry only (an hour). */
    private const RUN_SECONDS = 3_600;

    /**
     * The fixed chapters in the page's order: key, theme, boss character.
     *
     * @var list<array{key: string, theme: Theme, character: string}>
     */
    private const CHAPTERS = [
        ['key' => 'mates', 'theme' => Theme::Mates, 'character' => 'defi-mates'],
        ['key' => 'endgames', 'theme' => Theme::Endgames, 'character' => 'defi-endgames'],
        ['key' => 'tactics', 'theme' => Theme::Tactics, 'character' => 'defi-tactics'],
    ];

    /**
     * The fixed chapters, the same for every player.
     *
     * @return list<Chapter>
     */
    public static function chapters(): array
    {
        $chapters = [];
        foreach (self::CHAPTERS as $index => $chapter) {
            $rounds = [];
            $steps = [];
            for ($tier = 0; $tier < ParcoursRules::TIERS; ++$tier) {
                $themes = ParcoursRules::tier($chapter['theme'], $tier)['themes'];
                array_push($rounds, $themes, $themes);
                $steps[] = self::exercise(\sprintf('%s-%d', $chapter['key'], $tier + 1), new Activity(
                    Module::ParcoursPuzzles,
                    ['tier' => $tier, 'themes' => $themes, 'count' => self::PASS_COUNT],
                    self::RUN_SECONDS,
                    self::PASS_COUNT,
                    prepared: true,
                ));
            }
            $chapters[] = new Chapter($chapter['key'], $index + 1, [new Section($chapter['key'], null, $steps)], self::boss(
                $chapter['key'].'-boss',
                ['rounds' => $rounds],
                $chapter['character'],
                null,
            ), true);
        }

        return $chapters;
    }

    /**
     * An opening chapter, a repertoire's: lines, puzzles of its opening for its side, lines,
     * puzzles, lines, then the boss (puzzles of its opening). Without $subject (a repertoire no
     * longer there, the XP rebuild), its steps keep their kind, passes and XP but cannot be
     * played. `lines`: the repertoire's lines (null: not counted, a pass of {@see self::PASS_COUNT});
     * `paid`: its boss's crystals already paid for this opening and side; $meta: what the client
     * shows of the repertoire.
     *
     * @param array{repertoireId: string, family: string, color: string, lines: int|null, paid: bool}|null $subject
     * @param array<string, mixed>|null                                                                     $meta
     */
    public static function opening(string $code, int $number, ?array $subject, ?array $meta = null): Chapter
    {
        $key = self::OPENING_PREFIX.$code;
        $lines = null === $subject ? self::PASS_COUNT : max(1, min(self::PASS_COUNT, $subject['lines'] ?? self::PASS_COUNT));
        $steps = [];
        for ($tier = 0; $tier < ParcoursRules::TIERS; ++$tier) {
            $lineTier = ParcoursRules::LINES === ParcoursRules::tier(Theme::Opening, $tier)['kind'];
            $activity = match (true) {
                null === $subject => null,
                $lineTier => new Activity(
                    Module::Repertoire,
                    ['repertoireIds' => [$subject['repertoireId']], 'unit' => TestUnit::Line->value, 'limit' => $lines],
                    self::RUN_SECONDS,
                    $lines,
                    prepared: true,
                ),
                default => new Activity(
                    Module::ParcoursPuzzles,
                    ['tier' => $tier, 'family' => $subject['family'], 'color' => $subject['color'], 'count' => self::PASS_COUNT],
                    self::RUN_SECONDS,
                    self::PASS_COUNT,
                    prepared: true,
                ),
            };
            $steps[] = self::exercise(\sprintf('%s-%d', $key, $tier + 1), $activity);
        }
        $boss = null === $subject
            ? new Step($key.'-boss', null, StepKind::Boss, 1, 0, self::BOSS_XP, null, 'defi-opening')
            : self::boss($key.'-boss', ['family' => $subject['family'], 'color' => $subject['color']], 'defi-opening', self::rewardKey($subject['family'], $subject['color']), $subject['paid']);

        return new Chapter($key, $number, [new Section($key, null, $steps)], $boss, true, false, $meta);
    }

    /** An opening chapter's code: its repertoire's id in lower-case base32 (26 characters). */
    public static function code(Uuid $repertoireId): string
    {
        return strtolower($repertoireId->toBase32());
    }

    /**
     * The repertoire and the step of an opening chapter's key (`op-<code>-3`, `op-<code>-boss`),
     * null for another key.
     *
     * @return array{code: string, repertoireId: Uuid}|null
     */
    public static function parseOpening(string $key): ?array
    {
        if (1 !== preg_match('/^'.self::OPENING_PREFIX.'([0-9a-hjkmnp-tv-z]{26})-(?:[1-5]|boss)$/', $key, $match)) {
            return null;
        }
        try {
            return ['code' => $match[1], 'repertoireId' => Uuid::fromString(strtoupper($match[1]))];
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /** What an opening boss pays once: its opening and the player's side. */
    public static function rewardKey(string $family, string $color): string
    {
        return \sprintf('opening:%s:%s', $family, $color);
    }

    private static function exercise(string $key, ?Activity $activity): Step
    {
        return new Step($key, null, StepKind::Exercise, self::MASTERY_PASSES, 0, PathRules::EXERCISE_XP, $activity);
    }

    /**
     * A boss: ten puzzles (its tiers' `rounds`, or an opening `family` and `color`), above the
     * player's rating, 3 lives.
     *
     * @param array<string, mixed> $draw
     */
    private static function boss(string $key, array $draw, string $character, ?string $rewardKey, bool $paid = false): Step
    {
        return new Step($key, null, StepKind::Boss, 1, $paid ? 0 : self::BOSS_CRYSTALS, self::BOSS_XP, new Activity(
            Module::ParcoursPuzzles,
            [...$draw, 'count' => self::BOSS_COUNT, 'lives' => PathRules::BOSS_LIVES],
            self::RUN_SECONDS,
            self::BOSS_COUNT,
            PathRules::BOSS_LIVES,
            prepared: true,
        ), $character, rewardKey: $rewardKey);
    }
}
