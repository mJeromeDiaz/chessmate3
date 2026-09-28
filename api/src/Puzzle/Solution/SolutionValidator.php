<?php

declare(strict_types=1);

namespace App\Puzzle\Solution;

use App\Entity\Puzzle\Puzzle;
use PChess\Chess\Chess;

/**
 * Replays a submitted move log against a puzzle, server side: the client's own verdict is never
 * trusted. The log holds every move the player tried, in order, wrong ones included.
 *
 * Rules (Lichess'): the opponent's first move is played automatically; each player move must equal
 * the solution move (promotion piece included), except that any move giving checkmate wins, so an
 * alternative mate in one is accepted. A wrong move counts as a mistake and is taken back; the
 * player may keep searching. After a correct move the opponent's reply from the solution is played.
 */
final class SolutionValidator
{
    /** A puzzle has at most a dozen player moves; anything longer is not an honest log. */
    public const MAX_LOGGED_MOVES = 64;

    private const UCI_PATTERN = '/^([a-h][1-8])([a-h][1-8])([qrbn])?$/';

    /**
     * @param list<string> $loggedMoves UCI moves tried by the player
     *
     * @throws InvalidSubmissionException
     */
    public function replay(Puzzle $puzzle, array $loggedMoves): ReplayResult
    {
        if (\count($loggedMoves) > self::MAX_LOGGED_MOVES) {
            throw new InvalidSubmissionException('Too many moves.');
        }

        $solution = $puzzle->getMoveList();
        $chess = new Chess($puzzle->getFen());
        $this->play($chess, $solution[0]);

        $next = 1;
        $found = 0;
        $mistakes = 0;
        $completed = false;

        foreach ($loggedMoves as $move) {
            if ($completed) {
                throw new InvalidSubmissionException('Moves after the end of the puzzle.');
            }

            if (!$this->tryMove($chess, $move)) {
                throw new InvalidSubmissionException('Illegal move.');
            }

            if ($chess->inCheckmate()) {
                // Any mate wins, even one the solution reaches later or by another move.
                ++$found;
                $completed = true;
            } elseif ($move === $solution[$next]) {
                ++$found;
                if (isset($solution[$next + 1])) {
                    $this->play($chess, $solution[$next + 1]);
                }
                $next += 2;
                $completed = !isset($solution[$next]);
            } else {
                ++$mistakes;
                $chess->undo();
            }
        }

        return new ReplayResult($completed, $mistakes, $found);
    }

    /**
     * Plays a move of the puzzle's own solution (always legal for imported data).
     */
    private function play(Chess $chess, string $uci): void
    {
        if (!$this->tryMove($chess, $uci)) {
            throw new \UnexpectedValueException(sprintf('Puzzle solution move "%s" is illegal.', $uci));
        }
    }

    /**
     * Plays a UCI move if it is legal and exactly what was asked: the library would otherwise
     * accept a promotion letter on a non-promotion move ("e2e4q" played as "e2e4").
     */
    private function tryMove(Chess $chess, string $uci): bool
    {
        if (1 !== preg_match(self::UCI_PATTERN, $uci, $parts)) {
            return false;
        }

        $played = $chess->move(['from' => $parts[1], 'to' => $parts[2], 'promotion' => $parts[3] ?? null]);
        if (null === $played) {
            return false;
        }

        if ($played->from.$played->to.($played->promotion ?? '') !== $uci) {
            $chess->undo();

            return false;
        }

        return true;
    }
}
