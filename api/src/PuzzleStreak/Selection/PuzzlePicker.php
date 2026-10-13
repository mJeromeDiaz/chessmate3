<?php

declare(strict_types=1);

namespace App\PuzzleStreak\Selection;

use App\Entity\Catalog\Theme;
use App\Entity\Training\Run;
use App\Enum\PuzzleStreak\Ramp;
use App\PuzzleStreak\StreakRules;
use App\Puzzle\Selection\RandomSeeker;
use App\Puzzle\Selection\SelectionUnavailableException;
use App\Repository\Catalog\ThemeRepository;
use App\Repository\PuzzleStreak\AttemptRepository;

/**
 * Picks the next puzzle of a streak (docs/PUZZLE_STREAK.md): in the ramp's range for the puzzles
 * solved so far, widened step by step when it has nothing left; of the topic (the whole catalogue,
 * a theme, or an opening family with the player's side); never one already served in the run, and
 * one the user never played in a streak while there is one in a few random draws.
 */
final readonly class PuzzlePicker
{
    public function __construct(
        private RandomSeeker $seeker,
        private ThemeRepository $themes,
        private AttemptRepository $attempts,
    ) {
    }

    /**
     * @return int|null the puzzle id, null when even the widest range has none left
     *
     * @throws SelectionUnavailableException the selection index is being rebuilt
     */
    public function pick(Run $run, Ramp $ramp, Topic $topic, int $solved): ?int
    {
        $themeIds = [];
        if ([] !== $topic->themes) {
            $themeIds = array_map(static fn (Theme $t): int => (int) $t->getId(), $this->themes->findByKeys($topic->themes));
            if ([] === $themeIds) {
                return null;
            }
        }
        $inRun = $this->attempts->puzzleIdsOfRun($run);
        foreach (StreakRules::ranges($ramp, $solved) as [$low, $high]) {
            $fallback = null;
            for ($draw = 0; $draw < StreakRules::DRAWS; ++$draw) {
                $drawn = $topic->isOpening()
                    ? $this->seeker->drawOpening((string) $topic->family, $topic->solverColor, $low, $high, StreakRules::CANDIDATES)
                    : $this->seeker->draw($themeIds, $low, $high, StreakRules::CANDIDATES);
                $candidates = array_values(array_diff($drawn, $inRun));
                if ([] === $candidates) {
                    continue;
                }
                $fresh = array_values(array_diff($candidates, $this->attempts->playedAmong($run->getUser(), $candidates)));
                if ([] !== $fresh) {
                    return $fresh[0];
                }
                $fallback ??= $candidates[0];
            }
            if (null !== $fallback) {
                return $fallback;
            }
        }

        return null;
    }
}
