<?php

declare(strict_types=1);

namespace App\Calculation\Drawing;

use App\Calculation\CalculationRules;
use App\Chess\InvalidPositionException;
use App\Chess\Rules;
use App\Entity\Catalog\Puzzle;
use App\Entity\Catalog\Theme;
use App\Entity\Training\Run;
use App\Parcours\ParcoursRules;
use App\Puzzle\Catalog\PuzzleCatalog;
use App\Puzzle\Selection\RandomSeeker;
use App\Puzzle\Selection\SelectionUnavailableException;
use App\Repository\Calculation\AttemptRepository;
use App\Repository\Catalog\ThemeRepository;

/**
 * Draws the line of a calculation item (docs/CALCULATION.md, § 2): a puzzle tagged mateIn$mate whose
 * solution has the length of that mate (and, with $capture, a capture on the way), around the
 * target rating, widened step by step to any rating. Never a puzzle already served in the run while
 * there is another; one the user never played here first.
 */
final readonly class MatePicker
{
    public function __construct(
        private RandomSeeker $seeker,
        private ThemeRepository $themes,
        private PuzzleCatalog $catalog,
        private AttemptRepository $attempts,
    ) {
    }

    /**
     * @return Line|null null when no puzzle fits, even at any rating
     *
     * @throws SelectionUnavailableException the selection index is being rebuilt
     */
    public function pick(Run $run, int $mate, bool $capture, int $target): ?Line
    {
        $themeIds = array_map(static fn (Theme $t): int => (int) $t->getId(), $this->themes->findByKeys([CalculationRules::theme($mate)]));
        if ([] === $themeIds) {
            return null;
        }
        $served = $this->attempts->puzzleIdsOfRun($run);
        $again = null;
        foreach (ParcoursRules::ranges($target) as [$low, $high]) {
            $fallback = null;
            for ($draw = 0; $draw < CalculationRules::DRAWS; ++$draw) {
                $candidates = $this->seeker->draw($themeIds, $low, $high, CalculationRules::CANDIDATES);
                if ([] === $candidates) {
                    continue;
                }
                $played = $this->attempts->playedAmong($run->getUser(), $candidates);
                $puzzles = $this->catalog->byIds($candidates);
                foreach ($candidates as $id) {
                    $line = isset($puzzles[$id]) ? self::line($puzzles[$id], $mate, $capture) : null;
                    if (null === $line) {
                        continue;
                    }
                    if (\in_array($id, $served, true)) {
                        $again ??= $line;
                        continue;
                    }
                    if (!\in_array($id, $played, true)) {
                        return $line;
                    }
                    $fallback ??= $line;
                }
            }
            if (null !== $fallback) {
                return $fallback;
            }
        }

        // A small catalogue: one already served in the run rather than nothing.
        return $again;
    }

    /**
     * A puzzle made into a line, null when it does not fit (another length, no capture when one is
     * asked, a move that does not replay, no mate at its end).
     */
    public static function line(Puzzle $puzzle, int $mate, bool $capture): ?Line
    {
        $moves = explode(' ', trim($puzzle->getMoves()));
        $solution = \array_slice($moves, 1);
        if (\count($solution) !== CalculationRules::plies($mate)) {
            return null;
        }
        try {
            $rules = Rules::fromFen($puzzle->getFen());
            if (null === $rules->playUci($moves[0])) {
                return null;
            }
            $fen = $rules->fen();
            $captures = 0;
            foreach ($solution as $uci) {
                $move = $rules->playUci($uci);
                if (null === $move) {
                    return null;
                }
                $captures += null === $move->captured ? 0 : 1;
            }
        } catch (InvalidPositionException) {
            return null;
        }
        if (!$rules->inCheckmate() || ($capture && 0 === $captures)) {
            return null;
        }

        return new Line((int) $puzzle->getId(), $puzzle->getRating(), $mate, $fen, $solution);
    }
}
