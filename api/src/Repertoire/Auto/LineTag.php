<?php

declare(strict_types=1);

namespace App\Repertoire\Auto;

use App\Enum\Repertoire\Color;
use App\Repertoire\Limits;

/**
 * The tag of a line built from the user's games (docs/REPERTOIRE.md, "Repertoire from my games"),
 * from its score (wins + half the draws) and the cloud evaluation of its end, from the user's point
 * of view: at risk, a weapon, drawish, or solid. Thresholds in {@see Limits}.
 */
final readonly class LineTag
{
    public const RISK = 'risk';
    public const WEAPON = 'weapon';
    public const DRAWS = 'draws';
    public const SOLID = 'solid';

    public function __construct(
        private Limits $limits,
    ) {
    }

    /**
     * @param int|null $cp   evaluation, the user's point of view (centipawns)
     * @param int|null $mate mate in so many moves, the user's point of view (negative: mated)
     */
    public function of(int $games, int $wins, int $draws, ?int $cp, ?int $mate): string
    {
        $score = $games > 0 ? 100 * ($wins + $draws / 2) / $games : 0.0;
        $losing = (null !== $mate && $mate < 0) || (null !== $cp && $cp <= $this->limits->autoRiskEval);
        if ($score < $this->limits->autoRiskScore || $losing) {
            return self::RISK;
        }
        // Not losing (a mate against the user is): behind means a negative evaluation.
        if ($score >= $this->limits->autoWeaponScore && (null === $cp || $cp >= 0)) {
            return self::WEAPON;
        }

        return $games > 0 && 100 * $draws / $games >= $this->limits->autoDrawShare ? self::DRAWS : self::SOLID;
    }

    /**
     * A cloud evaluation (White's point of view) from the user's.
     *
     * @param array{cp: int|null, mate: int|null}|null $evaluation
     *
     * @return array{cp: int|null, mate: int|null}
     */
    public static function forUser(?array $evaluation, Color $color): array
    {
        $sign = Color::White === $color ? 1 : -1;

        return [
            'cp' => null === ($evaluation['cp'] ?? null) ? null : $sign * $evaluation['cp'],
            'mate' => null === ($evaluation['mate'] ?? null) ? null : $sign * $evaluation['mate'],
        ];
    }
}
