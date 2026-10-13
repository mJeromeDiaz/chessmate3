<?php

declare(strict_types=1);

namespace App\Coordinates\Series;

/**
 * The rules of the coordinates series (docs/COORDINATES.md), in one place: change a value here and
 * the API, the front (it reads them from GET /coordinates) and the validation follow.
 * Series already validated stay validated. Pure.
 */
final class CoordinateRules
{
    /** The lengths a series may last, in minutes (sessions count in minutes)... */
    public const SERIES_MINUTES = [1, 2, 5, 10];
    /** ...the one offered first. */
    public const DEFAULT_MINUTES = 2;
    /** A series validates its orientation with at least this many answers per minute it lasts... */
    public const MIN_ANSWERS_PER_MINUTE = 10;
    /** ...and at least this success rate, played to its end (time up, not stopped). */
    public const MIN_SUCCESS_RATE = 0.95;
    /** Squares drawn per second of a series: more than anyone can answer. */
    public const SQUARES_PER_SECOND = 3;
    /** Squares drawn for the longest series. */
    public const MAX_SQUARES = self::SQUARES_PER_SECOND * 60 * 10;
    /** Answers in one submission, at most (the client sends them every few seconds). */
    public const MAX_ANSWERS_PER_SUBMISSION = 100;

    /**
     * @return list<int> the lengths a series may last, in seconds
     */
    public static function budgets(): array
    {
        return array_map(static fn (int $minutes): int => $minutes * 60, self::SERIES_MINUTES);
    }

    public static function allows(int $seconds): bool
    {
        return \in_array($seconds, self::budgets(), true);
    }

    /** Answers needed to validate a series of this length. */
    public static function minAnswers(int $seconds): int
    {
        return intdiv($seconds, 60) * self::MIN_ANSWERS_PER_MINUTE;
    }

    /** Squares drawn for a series of this length. */
    public static function squares(int $seconds): int
    {
        return self::SQUARES_PER_SECOND * $seconds;
    }

    /**
     * Whether a series of this length validates its orientation.
     */
    public static function validates(int $seconds, int $answerCount, int $successCount, bool $playedToTheEnd): bool
    {
        return $playedToTheEnd
            && $answerCount >= self::minAnswers($seconds)
            && $successCount >= $answerCount * self::MIN_SUCCESS_RATE;
    }

    /**
     * The rules as the front reads them before a series (GET /coordinates).
     *
     * @return array{seriesMinutes: list<int>, defaultMinutes: int, minAnswersPerMinute: int, minSuccessRate: float}
     */
    public static function toArray(): array
    {
        return [
            'seriesMinutes' => self::SERIES_MINUTES,
            'defaultMinutes' => self::DEFAULT_MINUTES,
            'minAnswersPerMinute' => self::MIN_ANSWERS_PER_MINUTE,
            'minSuccessRate' => self::MIN_SUCCESS_RATE,
        ];
    }

    /**
     * The rules of one series, with its length (the player and the end of the series).
     *
     * @return array{seriesSeconds: int, minAnswers: int, minSuccessRate: float}
     */
    public static function ofSeries(int $seconds): array
    {
        return [
            'seriesSeconds' => $seconds,
            'minAnswers' => self::minAnswers($seconds),
            'minSuccessRate' => self::MIN_SUCCESS_RATE,
        ];
    }
}
