<?php

declare(strict_types=1);

namespace App\Blindfold\Recall;

use App\Chess\InvalidPositionException;
use App\Chess\Rules;
use App\Entity\Catalog\Puzzle;
use App\Entity\Training\Run;
use App\Puzzle\Catalog\PuzzleCatalog;
use App\Puzzle\Selection\RandomSeeker;
use App\Repository\Blindfold\RecallAttemptRepository;
use Random\Randomizer;

/**
 * Draws the puzzle of a recall level (docs/BLINDFOLD_RECALL.md): a selectable puzzle whose
 * position, once the opponent's first move is played, has the level's number of pieces, with enough
 * moves in its solution for those announced. Never one already served in the run; one the user
 * never rebuilt first, else one already rebuilt.
 */
final readonly class PuzzlePositionPicker
{
    private Randomizer $randomizer;

    public function __construct(
        private RandomSeeker $seeker,
        private PuzzleCatalog $catalog,
        private RecallAttemptRepository $attempts,
        ?Randomizer $randomizer = null,
    ) {
        $this->randomizer = $randomizer ?? new Randomizer();
    }

    /**
     * @return PuzzlePosition|null null when no puzzle fits
     */
    public function pick(Run $run, int $level): ?PuzzlePosition
    {
        $rules = RecallRules::level($level);
        if (null === $rules['puzzlePieces']) {
            throw new \InvalidArgumentException(\sprintf('Level %d is not a puzzle level.', $level));
        }
        [$min, $max] = $rules['puzzlePieces'];
        [$low, $high] = RecallRules::PUZZLE_RATINGS;
        $inRun = $this->attempts->puzzleIdsOfRun($run);
        $fallback = null;
        // Counted before the opponent's first move: a capture leaves one piece fewer.
        $counts = range($min, $max + 1);
        for ($draw = 0; $draw < RecallRules::DRAWS; ++$draw) {
            foreach ($this->randomizer->shuffleArray($counts) as $pieces) {
                $candidates = array_values(array_diff($this->seeker->drawByPieces($pieces, $low, $high, RecallRules::CANDIDATES), $inRun));
                if ([] === $candidates) {
                    continue;
                }
                $played = $this->attempts->playedAmong($run->getUser(), $candidates);
                $puzzles = $this->catalog->byIds($candidates);
                foreach ($candidates as $id) {
                    $position = isset($puzzles[$id]) ? self::position($puzzles[$id], $rules['announced']) : null;
                    if (null === $position || !self::fits($position->fen, $min, $max)) {
                        continue;
                    }
                    if (!\in_array($id, $played, true)) {
                        return $position;
                    }
                    $fallback ??= $position;
                }
            }
            if (null !== $fallback) {
                return $fallback;
            }
        }

        return $fallback;
    }

    /**
     * The puzzle's position after the opponent's first move, and after the $announced moves of its
     * solution; null when it has fewer moves or one of them does not replay.
     */
    public static function position(Puzzle $puzzle, int $announced): ?PuzzlePosition
    {
        $moves = explode(' ', trim($puzzle->getMoves()));
        if (\count($moves) < 1 + $announced) {
            return null;
        }
        try {
            $rules = Rules::fromFen($puzzle->getFen());
        } catch (InvalidPositionException) {
            return null;
        }
        if (null === $rules->playUci($moves[0])) {
            return null;
        }
        $shown = $rules->fen();
        $said = \array_slice($moves, 1, $announced);
        foreach ($said as $uci) {
            if (null === $rules->playUci($uci)) {
                return null;
            }
        }

        return new PuzzlePosition((int) $puzzle->getId(), $shown, explode(' ', $rules->fen())[0], $said);
    }

    private static function fits(string $fen, int $min, int $max): bool
    {
        $count = \count(Placement::squares($fen));

        return $count >= $min && $count <= $max;
    }
}
