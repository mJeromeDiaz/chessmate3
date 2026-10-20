<?php

declare(strict_types=1);

namespace App\Vision\Drawing;

use App\Chess\Attacks;
use App\Chess\InvalidPositionException;
use App\Chess\Rules;
use App\Entity\Catalog\Puzzle;
use App\Entity\Training\Run;
use App\Enum\Vision\Ask;
use App\Puzzle\Catalog\PuzzleCatalog;
use App\Puzzle\Selection\RandomSeeker;
use App\Repository\Vision\QuestionRepository;
use App\Vision\Board\EnPrise;
use App\Vision\VisionRules;
use Random\Randomizer;

/**
 * Draws an `en_prise` or `attackers` question (docs/VISION.md, § 2) from the puzzles: the position
 * after the opponent's first move ({@see VisionRules::PUZZLE_PIECES} pieces), then the next
 * {@see VisionRules::ANNOUNCED} moves of the solution, announced once it is hidden. The position they
 * reach must have a piece en prise, or a square to ask about: an opponent's piece attacked by the
 * side to move (else a square it attacks twice). Never a puzzle already served in the run; one the
 * user never met here first, else one already met.
 */
final readonly class LinePicker
{
    private Randomizer $randomizer;

    public function __construct(
        private RandomSeeker $seeker,
        private PuzzleCatalog $catalog,
        private QuestionRepository $questions,
        ?Randomizer $randomizer = null,
    ) {
        $this->randomizer = $randomizer ?? new Randomizer();
    }

    /**
     * @param Ask::EnPrise|Ask::Attackers $ask
     *
     * @return Draw|null null when no puzzle fits
     */
    public function pick(Run $run, Ask $ask): ?Draw
    {
        [$min, $max] = VisionRules::PUZZLE_PIECES;
        [$low, $high] = VisionRules::PUZZLE_RATINGS;
        $inRun = $this->questions->puzzleIdsOfRun($run);
        $fallback = null;
        // Counted before the opponent's first move: a capture leaves one piece fewer.
        $counts = range($min, $max + 1);
        for ($draw = 0; $draw < VisionRules::DRAWS; ++$draw) {
            foreach ($this->randomizer->shuffleArray($counts) as $pieces) {
                $candidates = array_values(array_diff($this->seeker->drawByPieces($pieces, $low, $high, VisionRules::CANDIDATES), $inRun));
                if ([] === $candidates) {
                    continue;
                }
                $met = $this->questions->metAmong($run->getUser(), $candidates);
                $puzzles = $this->catalog->byIds($candidates);
                foreach ($candidates as $id) {
                    $question = isset($puzzles[$id]) ? $this->question($puzzles[$id], $ask) : null;
                    if (null === $question) {
                        continue;
                    }
                    if (!\in_array($id, $met, true)) {
                        return $question;
                    }
                    $fallback ??= $question;
                }
            }
            if (null !== $fallback) {
                return $fallback;
            }
        }

        return $fallback;
    }

    /**
     * A puzzle made into a question, null when it does not fit (pieces, moves, nothing to ask).
     */
    public function question(Puzzle $puzzle, Ask $ask): ?Draw
    {
        $moves = explode(' ', trim($puzzle->getMoves()));
        if (\count($moves) < 1 + VisionRules::ANNOUNCED) {
            return null;
        }
        try {
            $rules = Rules::fromFen($puzzle->getFen());
            if (null === $rules->playUci($moves[0])) {
                return null;
            }
            $shown = $rules->fen();
            $pieces = \count(Attacks::board($shown));
            if ($pieces < VisionRules::PUZZLE_PIECES[0] || $pieces > VisionRules::PUZZLE_PIECES[1]) {
                return null;
            }
            $announced = \array_slice($moves, 1, VisionRules::ANNOUNCED);
            foreach ($announced as $uci) {
                if (null === $rules->playUci($uci)) {
                    return null;
                }
            }
            $final = $rules->fen();
            $side = 'w' === $rules->sideToMove() ? 'w' : 'b';
            if (Ask::EnPrise === $ask) {
                $squares = EnPrise::squares($final);

                return [] === $squares ? null : new Draw($shown, $announced, $ask, null, null, $squares, (int) $puzzle->getId(), VisionRules::PUZZLE_SECONDS);
            }
        } catch (InvalidPositionException) {
            return null;
        }
        $target = $this->target($final, $side);
        if (null === $target) {
            return null;
        }

        return new Draw($shown, $announced, Ask::Attackers, $target, 'w' === $side ? 'white' : 'black', Attacks::attackers($final, $target, $side), (int) $puzzle->getId(), VisionRules::PUZZLE_SECONDS);
    }

    /**
     * The square to ask about: an opponent's piece (not the king) attacked by $side, else a square
     * it attacks at least twice; null when there is none.
     *
     * @param 'w'|'b' $side
     */
    private function target(string $fen, string $side): ?string
    {
        $board = Attacks::board($fen);
        $pieces = [];
        $crowded = [];
        foreach (range(0, 63) as $index) {
            $square = \chr(\ord('a') + $index % 8).(intdiv($index, 8) + 1);
            $count = \count(Attacks::attackers($fen, $square, $side));
            $piece = $board[$square] ?? null;
            if (null !== $piece && 'k' !== strtolower($piece) && ('w' === $side) !== ctype_upper($piece) && $count >= 1) {
                $pieces[] = $square;
            } elseif ($count >= 2) {
                $crowded[] = $square;
            }
        }
        $choices = [] !== $pieces ? $pieces : $crowded;

        return [] === $choices ? null : $choices[$this->randomizer->getInt(0, \count($choices) - 1)];
    }
}
