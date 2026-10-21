<?php

declare(strict_types=1);

namespace App\Calculation\Drawing;

use App\Calculation\CalculationRules;
use App\Chess\InvalidPositionException;
use App\Chess\Rules;
use App\Entity\Catalog\Puzzle;
use App\Entity\Catalog\Theme;
use App\Entity\Training\Run;
use App\Enum\Calculation\Ending;
use App\Enum\Calculation\Kind;
use App\Parcours\ParcoursRules;
use App\Puzzle\Catalog\PuzzleCatalog;
use App\Puzzle\Selection\RandomSeeker;
use App\Puzzle\Selection\SelectionUnavailableException;
use App\Repository\Calculation\AttemptRepository;
use App\Repository\Catalog\ThemeRepository;

/**
 * Draws the line of a calculation item (docs/CALCULATION.md, § 2): a puzzle of the spec's themes
 * whose solution has one of its lengths, ends as it asks (a mate, or none) and, with `capture`, has
 * a capture on the way, and fits its kind (§ 2 quater); around the target rating, widened step by
 * step to any rating. Never a
 * puzzle already served in the run while there is another; one the user never played here first.
 */
final readonly class LinePicker
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
    public function pick(Run $run, LineSpec $spec, int $target): ?Line
    {
        $themeIds = array_map(static fn (Theme $t): int => (int) $t->getId(), $this->themes->findByKeys($spec->drawThemes()));
        if ([] === $themeIds) {
            return null;
        }
        $served = $this->attempts->puzzleIdsOfRun($run);
        $size = Kind::Line === $spec->kind ? CalculationRules::CANDIDATES : CalculationRules::CANDIDATES_FILTERED;
        $again = null;
        foreach (ParcoursRules::ranges($target) as [$low, $high]) {
            $fallback = null;
            for ($draw = 0; $draw < CalculationRules::DRAWS; ++$draw) {
                $candidates = $this->seeker->draw($themeIds, $low, $high, $size);
                if ([] === $candidates) {
                    continue;
                }
                $played = $this->attempts->playedAmong($run->getUser(), $candidates);
                $puzzles = $this->catalog->byIds($candidates);
                foreach ($candidates as $id) {
                    $line = isset($puzzles[$id]) ? self::line($puzzles[$id], $spec) : null;
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
     * A puzzle made into a line, null when it does not fit (another length, another ending, no
     * capture when one is asked, not what its kind asks, a move that does not replay).
     */
    public static function line(Puzzle $puzzle, LineSpec $spec): ?Line
    {
        $moves = explode(' ', trim($puzzle->getMoves()));
        $solution = \array_slice($moves, 1);
        $length = intdiv(\count($solution) + 1, 2);
        if (!\in_array($length, $spec->moves, true) || \count($solution) !== CalculationRules::plies($length)) {
            return null;
        }
        // A reply to look at, and a move of the player's after it.
        if (\in_array($spec->kind, [Kind::Reply, Kind::TwoReplies, Kind::Intermezzo], true) && \count($solution) < 3) {
            return null;
        }
        try {
            $rules = Rules::fromFen($puzzle->getFen());
            if (null === $rules->playUci($moves[0])) {
                return null;
            }
            $fen = $rules->fen();
            if (Kind::Defence === $spec->kind && !Threat::mateInOne($fen)) {
                return null;
            }
            $captures = 0;
            foreach ($solution as $i => $uci) {
                $move = $rules->playUci($uci);
                if (null === $move || !self::fits($spec->kind, $i, $rules)) {
                    return null;
                }
                $captures += null === $move->captured ? 0 : 1;
            }
        } catch (InvalidPositionException) {
            return null;
        }
        $ending = $rules->inCheckmate() ? Ending::Mate : Ending::Material;
        if ((null !== $spec->ending && $ending !== $spec->ending) || ($spec->capture && 0 === $captures)) {
            return null;
        }

        return new Line((int) $puzzle->getId(), $puzzle->getRating(), $length, $ending, $fen, $solution);
    }

    /**
     * Whether the line fits its kind once its half-move $i is played: 3.1 and 3.2 look at the
     * opponent's replies to the player's first move (a choice to make, exactly two), 3.4 at the
     * reply itself (a check).
     */
    private static function fits(Kind $kind, int $i, Rules $rules): bool
    {
        return match ($kind) {
            Kind::Reply => 0 !== $i || \count($rules->legalMoves()) >= 2,
            Kind::TwoReplies => 0 !== $i || CalculationRules::TWO_REPLIES === \count($rules->legalMoves()),
            Kind::Intermezzo => 1 !== $i || $rules->inCheck(),
            default => true,
        };
    }
}
