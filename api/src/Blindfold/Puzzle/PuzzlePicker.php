<?php

declare(strict_types=1);

namespace App\Blindfold\Puzzle;

use App\Entity\Catalog\Theme;
use App\Entity\Training\Run;
use App\Enum\Blindfold\PuzzleLevel;
use App\Puzzle\Selection\RandomSeeker;
use App\Puzzle\Selection\SelectionUnavailableException;
use App\Repository\Blindfold\PuzzleAttemptRepository;
use App\Repository\Catalog\ThemeRepository;

/**
 * Picks the next blindfold puzzle (docs/BLINDFOLD.md): within the level's fixed rating range, with
 * the length's theme (never a one-move puzzle), never one already served in the run, and one the
 * user never played blindfold while there is one in a few random draws.
 */
final readonly class PuzzlePicker
{
    public function __construct(
        private RandomSeeker $seeker,
        private ThemeRepository $themes,
        private PuzzleAttemptRepository $attempts,
    ) {
    }

    /**
     * @return int|null the puzzle id, null when the range has none left
     *
     * @throws SelectionUnavailableException the selection index is being rebuilt
     */
    public function pick(Run $run, PuzzleLevel $level, int $length): ?int
    {
        $theme = PuzzleRules::LENGTHS[$length] ?? throw new \InvalidArgumentException('Unknown length.');
        $themeIds = array_map(static fn (Theme $t): int => (int) $t->getId(), $this->themes->findByKeys([$theme]));
        if ([] === $themeIds) {
            return null;
        }
        [$low, $high] = PuzzleRules::ratings($level);
        $inRun = $this->attempts->puzzleIdsOfRun($run);
        $fallback = null;
        for ($draw = 0; $draw < PuzzleRules::DRAWS; ++$draw) {
            $candidates = array_values(array_diff($this->seeker->draw($themeIds, $low, $high, PuzzleRules::CANDIDATES), $inRun));
            if ([] === $candidates) {
                continue;
            }
            $fresh = array_values(array_diff($candidates, $this->attempts->playedAmong($run->getUser(), $candidates)));
            if ([] !== $fresh) {
                return $fresh[0];
            }
            $fallback ??= $candidates[0];
        }

        return $fallback;
    }
}
