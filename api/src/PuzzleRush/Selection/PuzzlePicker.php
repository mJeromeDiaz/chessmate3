<?php

declare(strict_types=1);

namespace App\PuzzleRush\Selection;

use App\Entity\Training\Run;
use App\PuzzleRush\RushRules;
use App\Puzzle\Selection\RandomSeeker;
use App\Puzzle\Selection\SelectionUnavailableException;
use App\Repository\PuzzleRush\AttemptRepository;

/**
 * Picks the next puzzle of a rush (docs/PUZZLE_RUSH.md): around the ramp's target, widened step by
 * step when it has nothing left; of the run's themes (OR, none: the whole catalogue); never one
 * already served in the run, and one the user never played in a rush while there is one in a few
 * random draws.
 */
final readonly class PuzzlePicker
{
    public function __construct(
        private RandomSeeker $seeker,
        private AttemptRepository $attempts,
    ) {
    }

    /**
     * @param list<int> $themeIds
     *
     * @return int|null the puzzle id, null when even the widest range has none left
     *
     * @throws SelectionUnavailableException the selection index is being rebuilt
     */
    public function pick(Run $run, array $themeIds, int $target): ?int
    {
        $inRun = $this->attempts->puzzleIdsOfRun($run);
        foreach (RushRules::ranges($target) as [$low, $high]) {
            $fallback = null;
            for ($draw = 0; $draw < RushRules::DRAWS; ++$draw) {
                $candidates = array_values(array_diff($this->seeker->draw($themeIds, $low, $high, RushRules::CANDIDATES), $inRun));
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
