<?php

declare(strict_types=1);

namespace App\Parcours\Selection;

use App\Entity\Catalog\Theme;
use App\Entity\Training\Run;
use App\Parcours\ParcoursRules;
use App\Puzzle\Selection\RandomSeeker;
use App\Puzzle\Selection\SelectionUnavailableException;
use App\Repository\Catalog\ThemeRepository;
use App\Repository\Parcours\AttemptRepository;

/**
 * Picks the next puzzle of a parcours tier (docs/PARCOURS.md): of the tier's themes (OR) or of an
 * opening family with the player's side, around the target rating, widened step by step to any
 * rating when the range has nothing left; never one already served in the parcours, and one the
 * user never played in a parcours while there is one in a few random draws.
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
     * @param list<string> $themes      Lichess theme keys (OR); empty with a family
     * @param string|null  $family      an opening family's catalogue tag ("Italian_Game")
     * @param string|null  $solverColor "w" or "b", the player's side in the family's puzzles
     *
     * @return int|null the puzzle id, null when even any rating has none left
     *
     * @throws SelectionUnavailableException the selection index is being rebuilt
     */
    public function pick(Run $run, array $themes, ?string $family, ?string $solverColor, int $target): ?int
    {
        $themeIds = [];
        if (null === $family) {
            $themeIds = array_map(static fn (Theme $t): int => (int) $t->getId(), $this->themes->findByKeys($themes));
            if ([] === $themeIds) {
                return null;
            }
        }
        $served = $this->attempts->puzzleIdsOfParcours($run);
        foreach (ParcoursRules::ranges($target) as [$low, $high]) {
            $fallback = null;
            for ($draw = 0; $draw < ParcoursRules::DRAWS; ++$draw) {
                $drawn = null !== $family
                    ? $this->seeker->drawOpening($family, $solverColor, $low, $high, ParcoursRules::CANDIDATES)
                    : $this->seeker->draw($themeIds, $low, $high, ParcoursRules::CANDIDATES);
                $candidates = array_values(array_diff($drawn, $served));
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
