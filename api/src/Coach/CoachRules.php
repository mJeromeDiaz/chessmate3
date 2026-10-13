<?php

declare(strict_types=1);

namespace App\Coach;

use App\Enum\Blindfold\PuzzleLevel;
use App\Evaluation\EvaluationRules;
use App\Repertoire\Auto\GameRecord;

/**
 * The rules of the tailored session (docs/COACH.md), in one place. Pure.
 *
 * An advantage at the endgame: the engine said at least ADVANTAGE_CP for the user when the game
 * entered its endgame (analysed games), or, without analysis, the user had at least
 * ADVANTAGE_POINTS of material more. Lost: the game was not won (drawn, lost, on time too).
 */
final class CoachRules
{
    /** Games read from Lichess, and their speeds. */
    public const GAMES = 200;
    public const PERFS = ['blitz', 'rapid', 'classical'];

    public const ADVANTAGE_CP = 200;
    public const ADVANTAGE_POINTS = 2;

    /** The session, always the same: minutes of each step. */
    public const WOODPECKER_MINUTES = 10;
    public const REPERTOIRE_MINUTES = 10;
    public const BLINDFOLD_MINUTES = 5;
    public const EVALUATION_MINUTES = 2;
    /** Blindfold puzzles: solutions of 2 moves, shown 10 s. */
    public const BLINDFOLD_LENGTH = 2;
    public const BLINDFOLD_VISIBLE_SECONDS = 10;
    /** Blindfold level by the user's rating: easy below the first, medium below the second. */
    public const BLINDFOLD_MEDIUM_FROM = 1400;
    public const BLINDFOLD_HARD_FROM = 1900;
    /** The rating used without any in the games. */
    public const DEFAULT_RATING = 1500;

    public const PLAN_TITLE = 'Ma séance sur mesure';

    /** Whether the user had the advantage when the game entered its endgame (null: no endgame). */
    public static function advantage(GameRecord $game): ?bool
    {
        if (null === $game->endgameType) {
            return null;
        }

        return null !== $game->endgameEval
            ? $game->endgameEval >= self::ADVANTAGE_CP
            : ($game->endgameBalance ?? 0) >= self::ADVANTAGE_POINTS;
    }

    /** An advantage at the endgame, and the game not won. */
    public static function lostAdvantage(GameRecord $game): bool
    {
        return true === self::advantage($game) && 1 !== $game->result;
    }

    public static function blindfoldLevel(int $rating): PuzzleLevel
    {
        return match (true) {
            $rating >= self::BLINDFOLD_HARD_FROM => PuzzleLevel::Hard,
            $rating >= self::BLINDFOLD_MEDIUM_FROM => PuzzleLevel::Medium,
            default => PuzzleLevel::Easy,
        };
    }

    /** The evaluation's level: the rating to the nearest hundred, within its bounds. */
    public static function evaluationElo(int $rating): int
    {
        return max(EvaluationRules::MIN_ELO, min(EvaluationRules::MAX_ELO, (int) round($rating / 100) * 100));
    }
}
