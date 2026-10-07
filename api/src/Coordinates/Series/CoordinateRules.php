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
    /** A series lasts this long (a whole number of minutes: sessions count in minutes). */
    public const SERIES_SECONDS = 300;
    /** A series validates its orientation with at least this many answers... */
    public const MIN_ANSWERS = 50;
    /** ...and at least this success rate, played to its end (time up, not stopped). */
    public const MIN_SUCCESS_RATE = 0.95;
    /** Squares drawn for a series: more than anyone can answer in SERIES_SECONDS. */
    public const SQUARES_PER_SERIES = 3 * self::SERIES_SECONDS;
    /** Answers in one submission, at most (the client sends them every few seconds). */
    public const MAX_ANSWERS_PER_SUBMISSION = 100;

    /**
     * Whether a series validates its orientation.
     */
    public static function validates(int $answerCount, int $successCount, bool $playedToTheEnd): bool
    {
        return $playedToTheEnd
            && $answerCount >= self::MIN_ANSWERS
            && $successCount >= $answerCount * self::MIN_SUCCESS_RATE;
    }

    /**
     * The rules as the front reads them.
     *
     * @return array{seriesSeconds: int, minAnswers: int, minSuccessRate: float}
     */
    public static function toArray(): array
    {
        return [
            'seriesSeconds' => self::SERIES_SECONDS,
            'minAnswers' => self::MIN_ANSWERS,
            'minSuccessRate' => self::MIN_SUCCESS_RATE,
        ];
    }
}
