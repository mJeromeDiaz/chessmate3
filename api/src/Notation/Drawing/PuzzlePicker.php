<?php

declare(strict_types=1);

namespace App\Notation\Drawing;

use App\Chess\InvalidPositionException;
use App\Chess\Rules;
use App\Entity\Catalog\Puzzle;
use App\Entity\Training\Run;
use App\Enum\Notation\Kind;
use App\Notation\NotationRules;
use App\Puzzle\Catalog\PuzzleCatalog;
use App\Puzzle\Selection\RandomSeeker;
use App\Repository\Notation\AttemptRepository;

/**
 * Draws the item of a notation exercise (docs/NOTATION.md, § 3) from the puzzles: the position once
 * the opponent's first move is played, and, by exercise, a move to announce ({@see MoveChooser}),
 * the solution to play (at least SEQUENCE_MIN_PLIES half-moves, cut at SEQUENCE_MAX_PLIES) or the
 * solution to write (WRITE_MAX_PLIES at most). Never a puzzle already served in the run; one the
 * user never played here first, else one already played.
 */
final readonly class PuzzlePicker
{
    public function __construct(
        private RandomSeeker $seeker,
        private PuzzleCatalog $catalog,
        private AttemptRepository $attempts,
        private MoveChooser $chooser,
    ) {
    }

    /**
     * @return Exercise|null null when no puzzle fits
     */
    public function pick(Run $run, Kind $kind): ?Exercise
    {
        [$low, $high] = NotationRules::PUZZLE_RATINGS;
        $inRun = $this->attempts->puzzleIdsOfRun($run);
        $fallback = null;
        for ($draw = 0; $draw < NotationRules::DRAWS; ++$draw) {
            $candidates = array_values(array_diff($this->seeker->draw([], $low, $high, NotationRules::CANDIDATES), $inRun));
            if ([] === $candidates) {
                continue;
            }
            $played = $this->attempts->playedAmong($run->getUser(), $candidates);
            $puzzles = $this->catalog->byIds($candidates);
            foreach ($candidates as $id) {
                $exercise = isset($puzzles[$id]) ? $this->exercise($puzzles[$id], $kind) : null;
                if (null === $exercise) {
                    continue;
                }
                if (!\in_array($id, $played, true)) {
                    return $exercise;
                }
                $fallback ??= $exercise;
            }
            if (null !== $fallback) {
                return $fallback;
            }
        }

        return $fallback;
    }

    /**
     * A puzzle made into an item of the exercise, null when it does not fit (too short, too long,
     * a move that does not replay).
     */
    public function exercise(Puzzle $puzzle, Kind $kind): ?Exercise
    {
        $moves = explode(' ', trim($puzzle->getMoves()));
        $solution = \array_slice($moves, 1);
        try {
            $rules = Rules::fromFen($puzzle->getFen());
            if ([] === $solution || null === $rules->playUci($moves[0])) {
                return null;
            }
            $fen = $rules->fen();
            $expected = match ($kind) {
                Kind::Play => self::announced($this->chooser->choose($fen)),
                Kind::Sequence => \count($solution) >= NotationRules::SEQUENCE_MIN_PLIES
                    ? \array_slice($solution, 0, NotationRules::SEQUENCE_MAX_PLIES)
                    : null,
                Kind::Write => \count($solution) <= NotationRules::WRITE_MAX_PLIES ? $solution : null,
                // Drawn from the repertoires ({@see LinePicker}).
                Kind::Line => null,
            };
        } catch (InvalidPositionException) {
            return null;
        }
        if (null === $expected || !self::replays($fen, $expected)) {
            return null;
        }

        return new Exercise((int) $puzzle->getId(), $fen, $expected);
    }

    /**
     * The move to announce, as the expected moves.
     *
     * @param array{uci: string, san: string}|null $move
     *
     * @return list<string>|null
     */
    private static function announced(?array $move): ?array
    {
        return null === $move ? null : [$move['uci']];
    }

    /**
     * @param list<string> $moves
     */
    private static function replays(string $fen, array $moves): bool
    {
        try {
            $rules = Rules::fromFen($fen);
        } catch (InvalidPositionException) {
            return false;
        }
        foreach ($moves as $uci) {
            if (null === $rules->playUci($uci)) {
                return false;
            }
        }

        return true;
    }
}
