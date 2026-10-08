<?php

declare(strict_types=1);

namespace App\Evaluation;

use App\Enum\Evaluation\AttemptStatus;

/**
 * The rules of the position evaluation (docs/EVALUATION.md, validated 2026-10-07), in one place.
 * Pure.
 *
 * Categories, from White's point of view: 2 White wins (+−), 1 White is better (±), 0 equal (=),
 * -1 Black is better (∓), -2 Black wins (−+).
 */
final class EvaluationRules
{
    /** Centipawns from which a side is better, then winning (a mate or a won endgame is winning). */
    public const ADVANTAGE_CP = 70;
    public const WINNING_CP = 200;
    /** A won position (mate, theoretical win) is stored as this many centipawns or more. */
    public const WON_CP = 10_000;

    public const MIN_COUNT = 3;
    public const MAX_COUNT = 20;
    /** Seconds per position the player may choose. */
    public const SECONDS = [30, 45, 60, 75, 90, 105, 120];
    public const MIN_ELO = 800;
    public const MAX_ELO = 2600;
    /** Positions within this many points of the chosen Elo first, then twice as far, then any. */
    public const ELO_WINDOW = 300;
    /** An answer this late after the position's time is still on time (the network). */
    public const TOLERANCE_MS = 2_000;
    /** Ideas per position, at most. */
    public const IDEAS = 3;
    /** A position's difficulty when the admin gives none. */
    public const DEFAULT_RATING = 1500;
    /** An evaluation this close to a category's border is flagged to the admin (a coin toss). */
    public const BORDER_MARGIN_CP = 20;

    /**
     * The category of an engine evaluation (centipawns, White's point of view).
     */
    public static function category(int $cp): int
    {
        $sign = $cp <=> 0;

        return match (true) {
            abs($cp) >= self::WINNING_CP => 2 * $sign,
            abs($cp) >= self::ADVANTAGE_CP => $sign,
            default => 0,
        };
    }

    /**
     * Whether an evaluation sits within BORDER_MARGIN_CP of a category's border.
     */
    public static function nearBorder(int $cp): bool
    {
        foreach ([self::ADVANTAGE_CP, self::WINNING_CP] as $border) {
            if (abs(abs($cp) - $border) < self::BORDER_MARGIN_CP) {
                return true;
            }
        }

        return false;
    }

    /**
     * The verdict on a guess (a category, null: no answer in time).
     */
    public static function status(?int $guess, int $category): AttemptStatus
    {
        return match (true) {
            null === $guess => AttemptStatus::Timeout,
            $guess === $category => AttemptStatus::Exact,
            1 === abs($guess - $category) => AttemptStatus::Close,
            default => AttemptStatus::Miss,
        };
    }

    /**
     * Exact in less than half the position's time.
     */
    public static function fast(AttemptStatus $status, int $durationMs, int $seconds): bool
    {
        return AttemptStatus::Exact === $status && $durationMs * 2 < $seconds * 1000;
    }

    /**
     * The engine's evaluation as shown: "+1,4", "−0,3", "0,0"; a won position "+−" / "−+".
     */
    public static function label(int $cp): string
    {
        if (abs($cp) >= self::WON_CP) {
            return $cp > 0 ? '+−' : '−+';
        }
        $pawns = number_format(abs($cp) / 100, 1, ',', '');

        return match ($cp <=> 0) {
            1 => '+'.$pawns,
            -1 => '−'.$pawns,
            default => $pawns,
        };
    }

    /**
     * The settings' bounds, for the front.
     *
     * @return array{minCount: int, maxCount: int, seconds: list<int>, minElo: int, maxElo: int, advantageCp: int, winningCp: int}
     */
    public static function toArray(): array
    {
        return [
            'minCount' => self::MIN_COUNT,
            'maxCount' => self::MAX_COUNT,
            'seconds' => self::SECONDS,
            'minElo' => self::MIN_ELO,
            'maxElo' => self::MAX_ELO,
            'advantageCp' => self::ADVANTAGE_CP,
            'winningCp' => self::WINNING_CP,
        ];
    }
}
