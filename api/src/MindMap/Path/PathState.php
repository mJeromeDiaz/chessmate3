<?php

declare(strict_types=1);

namespace App\MindMap\Path;

use App\Enum\MindMap\StepKind;
use App\Enum\MindMap\StepStatus;

/**
 * Where the player stands on a path (docs/MIND_MAP.md, § 3), from their progress only. Pure.
 *
 * - The first chapter is open; a chapter opens when the boss of the previous one is beaten.
 * - In an open chapter, a section opens when the previous one is cleared: its exercises passed or
 *   skipped, its mini-bosses beaten.
 * - In an open section, the exercises open in order (one passed or skipped opens the next); the
 *   mini-bosses open, in order, once every exercise is passed or skipped; the hidden bosses show
 *   once the section is cleared.
 * - The chapter's boss is open with its chapter: a strong player may beat it at once, without the
 *   exercises or the mini-bosses, and open the next chapter.
 * - A chest is earned (done) once every exercise before it in its section is passed, never by a
 *   skip ({@see self::chestsDue()}).
 * - A reachable step without module is « soon »: an exercise may be skipped, a boss blocks.
 *
 * @phpstan-type StepProgress array{passes: int, done: bool, skipped: bool}
 */
final class PathState
{
    /**
     * @param list<Chapter>               $chapters
     * @param array<string, StepProgress> $progress by step key
     *
     * @return array<string, StepStatus> by step key
     */
    public static function statuses(array $chapters, array $progress): array
    {
        $statuses = [];
        $chapterOpen = true;
        foreach ($chapters as $chapter) {
            $sectionOpen = $chapterOpen;
            foreach ($chapter->sections as $section) {
                $statuses += self::sectionStatuses($section, $progress, $sectionOpen);
                $sectionOpen = $sectionOpen && self::isCleared($section, $progress);
            }
            $statuses[$chapter->boss->key] = self::reachable($chapter->boss, $progress, $chapterOpen);
            $chapterOpen = $chapterOpen && self::isDone($chapter->boss, $progress);
        }

        return $statuses;
    }

    /**
     * The chests the player has earned but not received: every exercise before them in their
     * section passed.
     *
     * @param list<Chapter>               $chapters
     * @param array<string, StepProgress> $progress
     *
     * @return list<Step>
     */
    public static function chestsDue(array $chapters, array $progress): array
    {
        $due = [];
        foreach ($chapters as $chapter) {
            foreach ($chapter->sections as $section) {
                $passed = true;
                foreach ($section->steps as $step) {
                    if (StepKind::Chest === $step->kind) {
                        if ($passed && !self::isDone($step, $progress)) {
                            $due[] = $step;
                        }
                        continue;
                    }
                    $passed = $passed && self::isDone($step, $progress);
                }
            }
        }

        return $due;
    }

    /**
     * Whether a section is cleared: its exercises passed or skipped, its mini-bosses beaten.
     *
     * @param array<string, StepProgress> $progress
     */
    public static function isCleared(Section $section, array $progress): bool
    {
        foreach ($section->steps as $step) {
            if (StepKind::Exercise === $step->kind && !self::isPassedOrSkipped($step, $progress)) {
                return false;
            }
        }
        foreach ($section->miniBosses as $boss) {
            if (!self::isDone($boss, $progress)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, StepProgress> $progress
     *
     * @return array<string, StepStatus>
     */
    private static function sectionStatuses(Section $section, array $progress, bool $open): array
    {
        $statuses = [];
        // Whether every exercise so far is passed or skipped.
        $cleared = $open;
        foreach ($section->steps as $step) {
            if (StepKind::Chest === $step->kind) {
                $statuses[$step->key] = self::isDone($step, $progress) ? StepStatus::Done : StepStatus::Locked;
                continue;
            }
            $statuses[$step->key] = match (true) {
                self::isDone($step, $progress) => StepStatus::Done,
                ($progress[$step->key]['skipped'] ?? false) => StepStatus::Skipped,
                $cleared => $step->isPlayable() ? StepStatus::Open : StepStatus::Soon,
                default => StepStatus::Locked,
            };
            $cleared = $cleared && self::isPassedOrSkipped($step, $progress);
        }
        foreach ($section->miniBosses as $boss) {
            $statuses[$boss->key] = self::reachable($boss, $progress, $cleared);
            $cleared = $cleared && self::isDone($boss, $progress);
        }
        foreach ($section->hidden as $boss) {
            $statuses[$boss->key] = $cleared ? self::reachable($boss, $progress, true) : StepStatus::Hidden;
        }

        return $statuses;
    }

    /**
     * @param array<string, StepProgress> $progress
     */
    private static function reachable(Step $step, array $progress, bool $reached): StepStatus
    {
        return match (true) {
            self::isDone($step, $progress) => StepStatus::Done,
            !$reached => StepStatus::Locked,
            $step->isPlayable() => StepStatus::Open,
            default => StepStatus::Soon,
        };
    }

    /**
     * @param array<string, StepProgress> $progress
     */
    private static function isDone(Step $step, array $progress): bool
    {
        return $progress[$step->key]['done'] ?? false;
    }

    /**
     * @param array<string, StepProgress> $progress
     */
    private static function isPassedOrSkipped(Step $step, array $progress): bool
    {
        return self::isDone($step, $progress) || ($progress[$step->key]['skipped'] ?? false);
    }
}
