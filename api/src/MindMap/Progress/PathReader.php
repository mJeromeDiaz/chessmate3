<?php

declare(strict_types=1);

namespace App\MindMap\Progress;

use App\Entity\MindMap\Chest;
use App\Entity\MindMap\Progress;
use App\Entity\User;
use App\Enum\MindMap\Capacity;
use App\Enum\MindMap\StepKind;
use App\Enum\MindMap\StepStatus;
use App\MindMap\Path\Paths;
use App\MindMap\Path\PathState;
use App\MindMap\Path\Chapter;
use App\MindMap\Path\Step;
use App\Repository\MindMap\AttemptRepository;
use App\Repository\MindMap\ChestRepository;
use App\Repository\MindMap\ProgressRepository;

/**
 * The player's path as the client shows it (docs/MIND_MAP.md, § 7). A hidden boss shows nothing
 * of itself but its place.
 *
 * @phpstan-type ChestView array{id: string, step: string, crystals: int, opened: bool}
 * @phpstan-type StepView array{key: string, number: string|null, kind: string, status: string, passes: int, required: int, crystals: int, xp: int, character: string|null, badges: list<string>, skippable: bool, chest: ChestView|null}
 * @phpstan-type SectionView array{key: string, number: string|null, status: string, steps: list<StepView>, miniBosses: list<StepView>, hidden: list<StepView>}
 * @phpstan-type ChapterView array{key: string, number: int, status: string, sections: list<SectionView>, boss: StepView, meta: array<string, mixed>|null}
 * @phpstan-type PathView array{capacity: string, chapters: list<ChapterView>, badges: list<array{badge: string, step: string, earnedAt: string}>, unopenedChests: list<ChestView>, lastPlayedAt: string|null}
 */
final readonly class PathReader
{
    public function __construct(
        private ProgressRepository $progress,
        private ChestRepository $chests,
        private PathCatalog $catalog,
        private AttemptRepository $attempts,
    ) {
    }

    /**
     * @return PathView
     */
    public function read(User $user, Capacity $capacity): array
    {
        $rows = $this->progress->byStep($user, $capacity);
        $chests = $this->chests->byStep($user, $capacity);
        $state = Progresses::state($rows);
        $chapters = $this->catalog->chapters($user, $capacity);
        $statuses = PathState::statuses($chapters, $state);

        $views = [];
        $chapterOpen = true;
        foreach ($chapters as $chapter) {
            $sections = [];
            $chapterOpen = $chapterOpen || $chapter->free;
            $sectionOpen = $chapterOpen;
            foreach ($chapter->sections as $section) {
                $cleared = $sectionOpen && PathState::isCleared($section, $state);
                $sections[] = [
                    'key' => $section->key,
                    'number' => $section->number,
                    'status' => self::areaStatus($sectionOpen, $cleared),
                    'steps' => $this->steps($section->steps, $statuses, $rows, $chests),
                    'miniBosses' => $this->steps($section->miniBosses, $statuses, $rows, $chests),
                    'hidden' => $this->steps($section->hidden, $statuses, $rows, $chests),
                ];
                $sectionOpen = $cleared || $chapter->free;
            }
            $bossDone = StepStatus::Done === ($statuses[$chapter->boss->key] ?? null);
            $views[] = [
                'key' => $chapter->key,
                'number' => $chapter->number,
                'status' => self::areaStatus($chapterOpen, $bossDone),
                'sections' => $sections,
                'boss' => $this->step($chapter->boss, $statuses, $rows, $chests),
                'meta' => $chapter->meta,
            ];
            $chapterOpen = $bossDone;
        }

        return [
            'capacity' => $capacity->value,
            'chapters' => $views,
            'badges' => $this->badges($capacity, $chapters, $rows),
            'unopenedChests' => $this->unopened($chests),
            'lastPlayedAt' => $this->attempts->lastStartedAt($user, $capacity)?->format(\DATE_ATOM),
        ];
    }

    /**
     * The player's chests not opened yet on the path, oldest first.
     *
     * @return list<ChestView>
     */
    public function unopenedChests(User $user, Capacity $capacity): array
    {
        return $this->unopened($this->chests->byStep($user, $capacity));
    }

    /**
     * @return ChestView
     */
    public static function chestView(Chest $chest): array
    {
        return [
            'id' => $chest->getId()->toRfc4122(),
            'step' => $chest->getStep(),
            'crystals' => $chest->getCrystals(),
            'opened' => $chest->isOpened(),
        ];
    }

    /**
     * @param array<string, Chest> $chests
     *
     * @return list<ChestView>
     */
    private function unopened(array $chests): array
    {
        $unopened = array_values(array_filter($chests, static fn (Chest $chest): bool => !$chest->isOpened()));
        usort($unopened, static fn (Chest $a, Chest $b): int => [$a->getCreatedAt(), $a->getId()->toRfc4122()] <=> [$b->getCreatedAt(), $b->getId()->toRfc4122()]);

        return array_map(self::chestView(...), $unopened);
    }

    /**
     * @param list<Step>                $steps
     * @param array<string, StepStatus> $statuses
     * @param array<string, Progress>   $rows
     * @param array<string, Chest>      $chests
     *
     * @return list<StepView>
     */
    private function steps(array $steps, array $statuses, array $rows, array $chests): array
    {
        return array_map(fn (Step $step): array => $this->step($step, $statuses, $rows, $chests), $steps);
    }

    /**
     * @param array<string, StepStatus> $statuses
     * @param array<string, Progress>   $rows
     * @param array<string, Chest>      $chests
     *
     * @return StepView
     */
    private function step(Step $step, array $statuses, array $rows, array $chests): array
    {
        $status = $statuses[$step->key] ?? StepStatus::Locked;
        $hidden = StepStatus::Hidden === $status;
        $chest = $chests[$step->key] ?? null;

        return [
            'key' => $step->key,
            'number' => $step->number,
            'kind' => $step->kind->value,
            'status' => $status->value,
            'passes' => ($rows[$step->key] ?? null)?->getPasses() ?? 0,
            'required' => $step->passes,
            'crystals' => $hidden ? 0 : $step->crystals,
            'xp' => $hidden ? 0 : $step->xp,
            'character' => $hidden ? null : $step->character,
            'badges' => $hidden ? [] : $step->badges,
            'skippable' => StepKind::Exercise === $step->kind && \in_array($status, [StepStatus::Open, StepStatus::Soon], true),
            'chest' => null === $chest ? null : self::chestView($chest),
        ];
    }

    /**
     * The badges earned: those of the steps completed, when they were; then the path's mastery
     * badge ({@see Paths::masteryBadge()}), every exercise of its mastery chapters completed
     * ({@see Chapter::$mastery}), when the last one was.
     *
     * @param list<Chapter>           $chapters
     * @param array<string, Progress> $rows
     *
     * @return list<array{badge: string, step: string, earnedAt: string}>
     */
    private function badges(Capacity $capacity, array $chapters, array $rows): array
    {
        $badges = [];
        $mastered = true;
        $last = null;
        foreach ($chapters as $chapter) {
            foreach ($chapter->allSteps() as $step) {
                $at = ($rows[$step->key] ?? null)?->getCompletedAt();
                if (StepKind::Exercise === $step->kind && $chapter->mastery) {
                    $mastered = $mastered && null !== $at;
                    if (null !== $at && (null === $last || $at > $last['at'])) {
                        $last = ['step' => $step->key, 'at' => $at];
                    }
                }
                if (null === $at) {
                    continue;
                }
                foreach ($step->badges as $badge) {
                    $badges[] = ['badge' => $badge, 'step' => $step->key, 'earnedAt' => $at->format(\DATE_ATOM)];
                }
            }
        }
        $badge = Paths::masteryBadge($capacity);
        if (null !== $badge && $mastered && null !== $last) {
            $badges[] = ['badge' => $badge, 'step' => $last['step'], 'earnedAt' => $last['at']->format(\DATE_ATOM)];
        }

        return $badges;
    }

    /** A chapter or a section: locked, open, or done. */
    private static function areaStatus(bool $open, bool $done): string
    {
        return match (true) {
            $done => StepStatus::Done->value,
            $open => StepStatus::Open->value,
            default => StepStatus::Locked->value,
        };
    }
}
