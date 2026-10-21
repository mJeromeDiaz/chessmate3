<?php

declare(strict_types=1);

namespace App\Vision\Drawing;

use App\Entity\Catalog\Theme;
use App\Entity\Training\Run;
use App\Puzzle\Catalog\PuzzleCatalog;
use App\Puzzle\Selection\RandomSeeker;
use App\Repository\Catalog\ThemeRepository;
use App\Repository\Vision\QuestionRepository;
use App\Vision\VisionRules;

/**
 * Draws a position of the Compteur, boss of chapter 2 of the calculation path (docs/VISION.md,
 * § 2 sexies): a long puzzle (Lichess `veryLong`) made into its questions by {@see CounterPosition}.
 * Never a puzzle already served in the run while there is another.
 */
final readonly class CounterDrawer
{
    public function __construct(
        private RandomSeeker $seeker,
        private ThemeRepository $themes,
        private PuzzleCatalog $catalog,
        private QuestionRepository $questions,
        private CounterPosition $positions,
    ) {
    }

    public function draw(Run $run): ?Draw
    {
        $themeIds = array_map(static fn (Theme $t): int => (int) $t->getId(), $this->themes->findByKeys(['veryLong']));
        if ([] === $themeIds) {
            return null;
        }
        [$low, $high] = VisionRules::PUZZLE_RATINGS;
        $inRun = $this->questions->puzzleIdsOfRun($run);
        $again = null;
        for ($draw = 0; $draw < VisionRules::DRAWS; ++$draw) {
            $candidates = $this->seeker->draw($themeIds, $low, $high, VisionRules::CANDIDATES);
            $puzzles = $this->catalog->byIds($candidates);
            foreach ($candidates as $id) {
                $question = isset($puzzles[$id]) ? $this->positions->of($puzzles[$id]) : null;
                if (null === $question) {
                    continue;
                }
                if (!\in_array($id, $inRun, true)) {
                    return $question;
                }
                $again ??= $question;
            }
        }

        // A small catalogue: one already served in the run rather than nothing.
        return $again;
    }
}
