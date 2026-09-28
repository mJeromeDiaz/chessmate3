<?php

declare(strict_types=1);

namespace App\Puzzle\Rating;

/**
 * The Glicko-2 rating system, as specified in Mark Glickman's "Example of the Glicko-2 system"
 * (http://www.glicko.net/glicko/glicko2.pdf, steps 1 to 8). Pure and stateless: the caller decides
 * what a rating period is and applies its own bounds ({@see RatingCalculator}).
 */
final class Glicko2
{
    /** Conversion factor between the Glicko and the Glicko-2 scales (step 2). */
    private const SCALE = 173.7178;
    private const BASE_RATING = 1500.0;
    /** Convergence tolerance of the volatility iteration (step 5). */
    private const EPSILON = 0.000001;

    /**
     * @param float $tau system constant constraining the volatility change over time (Glickman: 0.3–1.2)
     */
    public function __construct(private readonly float $tau)
    {
    }

    /**
     * Rates one period. With no game, only the deviation grows (step 6 applied alone).
     *
     * @param list<GameResult> $games
     */
    public function rate(RatingState $player, array $games): RatingState
    {
        $mu = ($player->rating - self::BASE_RATING) / self::SCALE;
        $phi = $player->deviation / self::SCALE;
        $sigma = $player->volatility;

        if ([] === $games) {
            return new RatingState($player->rating, sqrt($phi ** 2 + $sigma ** 2) * self::SCALE, $sigma);
        }

        // Step 3 (estimated variance v) and step 4 (estimated improvement Δ, kept as its sum).
        $inverseV = 0.0;
        $scoreSum = 0.0;
        foreach ($games as $game) {
            $muJ = ($game->opponentRating - self::BASE_RATING) / self::SCALE;
            $g = self::g($game->opponentDeviation / self::SCALE);
            $e = self::expectedScoreScaled($mu, $muJ, $g);
            $inverseV += $g ** 2 * $e * (1 - $e);
            $scoreSum += $g * ($game->score - $e);
        }
        $v = 1 / $inverseV;
        $delta = $v * $scoreSum;

        // Step 5: new volatility.
        $newSigma = $this->newVolatility($phi, $sigma, $v, $delta);

        // Steps 6 and 7: new deviation and rating.
        $phiStar = sqrt($phi ** 2 + $newSigma ** 2);
        $newPhi = 1 / sqrt(1 / $phiStar ** 2 + 1 / $v);
        $newMu = $mu + $newPhi ** 2 * $scoreSum;

        // Step 8: back to the Glicko scale.
        return new RatingState(self::SCALE * $newMu + self::BASE_RATING, self::SCALE * $newPhi, $newSigma);
    }

    /**
     * Deviation growth over several periods without games: φ* = √(φ² + t·σ²), Glickman's step 6
     * generalised to t periods (volatility unchanged).
     */
    public function inflate(RatingState $player, float $periods): RatingState
    {
        $phi = $player->deviation / self::SCALE;

        return new RatingState(
            $player->rating,
            sqrt($phi ** 2 + $periods * $player->volatility ** 2) * self::SCALE,
            $player->volatility,
        );
    }

    /**
     * Expected score of a player against an opponent, both on the Glicko scale (E in step 3).
     */
    public function expectedScore(float $rating, float $opponentRating, float $opponentDeviation): float
    {
        return self::expectedScoreScaled(
            ($rating - self::BASE_RATING) / self::SCALE,
            ($opponentRating - self::BASE_RATING) / self::SCALE,
            self::g($opponentDeviation / self::SCALE),
        );
    }

    /**
     * Step 5, with the Illinois algorithm (the 2013 revision of Glickman's paper).
     */
    private function newVolatility(float $phi, float $sigma, float $v, float $delta): float
    {
        $a = log($sigma ** 2);
        $tauSquared = $this->tau ** 2;
        $f = static function (float $x) use ($phi, $v, $delta, $a, $tauSquared): float {
            $ex = exp($x);

            return $ex * ($delta ** 2 - $phi ** 2 - $v - $ex) / (2 * ($phi ** 2 + $v + $ex) ** 2) - ($x - $a) / $tauSquared;
        };

        $bigA = $a;
        if ($delta ** 2 > $phi ** 2 + $v) {
            $bigB = log($delta ** 2 - $phi ** 2 - $v);
        } else {
            $k = 1;
            while ($f($a - $k * $this->tau) < 0) {
                ++$k;
            }
            $bigB = $a - $k * $this->tau;
        }

        $fA = $f($bigA);
        $fB = $f($bigB);
        while (abs($bigB - $bigA) > self::EPSILON) {
            $bigC = $bigA + ($bigA - $bigB) * $fA / ($fB - $fA);
            $fC = $f($bigC);
            if ($fC * $fB <= 0) {
                $bigA = $bigB;
                $fA = $fB;
            } else {
                $fA /= 2;
            }
            $bigB = $bigC;
            $fB = $fC;
        }

        return exp($bigA / 2);
    }

    private static function g(float $phi): float
    {
        return 1 / sqrt(1 + 3 * $phi ** 2 / M_PI ** 2);
    }

    private static function expectedScoreScaled(float $mu, float $muJ, float $g): float
    {
        return 1 / (1 + exp(-$g * ($mu - $muJ)));
    }
}
