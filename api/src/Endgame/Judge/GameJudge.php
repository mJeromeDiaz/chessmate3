<?php

declare(strict_types=1);

namespace App\Endgame\Judge;

use App\Chess\InvalidPositionException;
use App\Chess\Rules;
use App\Endgame\Lesson\Lesson;
use App\Endgame\Tablebase\BestDefence;
use App\Endgame\Tablebase\MoveAnswer;
use App\Endgame\Tablebase\Tablebase;
use App\Enum\Endgame\AttemptStatus;
use App\Enum\Endgame\Goal;
use App\Training\Exception\InvalidItemSubmissionException;
use PChess\Chess\Move;

/**
 * Judges a whole game of the endgames module (docs/ENDGAMES.md, § 5), replayed from the position
 * served: each move of the player must keep the lesson's result (the tablebase's answers kept in
 * the endgame database, never asked here: the browser asked them while playing), the goal reached
 * within the moves allowed. At the top of the slider the opponent's moves must be among the best
 * defence; Maia's are only checked to be legal.
 *
 * A position missing from the kept answers (the tablebase was unavailable: the browser's engine
 * judged) takes the engine's verdict sent with the game.
 */
final readonly class GameJudge
{
    public function __construct(
        private Tablebase $tablebase,
    ) {
    }

    /**
     * @param list<string>                                $moves  the game, both sides, UCI
     * @param array{right: bool, mistakeAt?: int|null}|null $engine the browser engine's verdict
     *
     * @throws InvalidItemSubmissionException an illegal or unfinished game, moves after its end
     */
    public function judge(Lesson $lesson, string $fen, array $moves, ?int $budgetMoves, ?int $holdMoves, bool $perfect, bool $resigned, ?array $engine): Verdict
    {
        try {
            $rules = Rules::fromFen($fen);
        } catch (InvalidPositionException $e) {
            throw new InvalidItemSubmissionException($e->getMessage(), 0, $e);
        }
        $player = $rules->sideToMove();
        $playerMoves = 0;
        $judged = true;
        $status = null;

        foreach ($moves as $ply => $uci) {
            if (null !== $status) {
                throw new InvalidItemSubmissionException(\sprintf('Move %d comes after the end of the position.', $ply));
            }
            $mine = $rules->sideToMove() === $player;
            // The opponent's positions matter for the best defence only (Maia's are not asked).
            $needed = $mine || $perfect;
            $answer = $judged && $needed ? $this->tablebase->lookup($rules->normalizedFen()) : null;
            $judged = $judged && (!$needed || null !== $answer);

            if (null !== $answer && $mine) {
                $keeping = array_map(static fn (MoveAnswer $move): string => $move->uci, $answer->keepingMoves());
                if ($answer->result() !== $lesson->result || !\in_array($uci, $keeping, true)) {
                    self::play($rules, $uci, $ply);

                    return new Verdict(AttemptStatus::Spoiled, $playerMoves + 1, $ply, $keeping[0] ?? null);
                }
            }
            if (null !== $answer && !$mine && $perfect && !\in_array($uci, BestDefence::moves($answer), true)) {
                throw new InvalidItemSubmissionException(\sprintf('Move %d is not among the best defence.', $ply));
            }

            $move = self::play($rules, $uci, $ply);
            if ($mine) {
                ++$playerMoves;
            }
            $status = self::reached($lesson, $rules, $move, $mine, $playerMoves, $holdMoves);
            if (null === $status && $mine && null !== $budgetMoves && $playerMoves >= $budgetMoves) {
                $status = AttemptStatus::Overtime;
            }
        }

        if (!$judged) {
            if (null === $engine) {
                throw new InvalidItemSubmissionException('The tablebase did not judge this game: the engine\'s verdict is expected.');
            }
            if (!$engine['right']) {
                return new Verdict(AttemptStatus::Spoiled, $playerMoves, $engine['mistakeAt'] ?? null, null, true);
            }
        }
        $status ??= $resigned ? AttemptStatus::Resigned : throw new InvalidItemSubmissionException('The position is not over.');

        return new Verdict($status, $playerMoves, engineJudged: !$judged);
    }

    /**
     * The goal of the lesson, reached by the move just played (null: not yet).
     */
    private static function reached(Lesson $lesson, Rules $rules, Move $move, bool $mine, int $playerMoves, ?int $holdMoves): ?AttemptStatus
    {
        if (Goal::Hold === $lesson->goal) {
            $dead = $rules->inStalemate() || $rules->insufficientMaterial();

            return $dead || ($mine && $playerMoves >= ($holdMoves ?? $lesson->holdMoves)) ? AttemptStatus::Held : null;
        }
        if (!$mine) {
            return null;
        }

        return match ($lesson->goal) {
            Goal::Mate => $rules->inCheckmate(),
            Goal::Promote => null !== $move->promotion,
            Goal::Convert => self::bareKing($rules),
        } ? AttemptStatus::Won : null;
    }

    /** The side to move (the opponent, after the player's move) has its king only. */
    private static function bareKing(Rules $rules): bool
    {
        $placement = explode(' ', $rules->normalizedFen())[0];
        $pieces = 'w' === $rules->sideToMove() ? preg_replace('/[^A-Z]/', '', $placement) : preg_replace('/[^a-z]/', '', $placement);

        return 1 === \strlen($pieces ?? '');
    }

    /**
     * @throws InvalidItemSubmissionException
     */
    private static function play(Rules $rules, string $uci, int $ply): Move
    {
        return $rules->playUci($uci) ?? throw new InvalidItemSubmissionException(\sprintf('Move %d (%s) is illegal.', $ply, $uci));
    }
}
