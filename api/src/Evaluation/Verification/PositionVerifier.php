<?php

declare(strict_types=1);

namespace App\Evaluation\Verification;

use App\Enum\Repertoire\Color;
use App\Evaluation\EvaluationRules;
use App\Repertoire\Lichess\CloudEvalClient;
use App\Repertoire\Lichess\LichessUnavailableException;

/**
 * Checks a position an admin enters against Lichess (docs/EVALUATION.md): the tablebase for 7 pieces
 * or fewer (exact), the cloud evaluation otherwise (when Lichess has it). Compares categories; a
 * cloud evaluation in the same category but far from the stored one is reported too.
 *
 * @phpstan-type Check array{verdict: 'ok'|'mismatch'|'drift'|'unverified'|'unavailable', source: string, lichess: string|null, lichessCategory: int|null}
 */
final readonly class PositionVerifier
{
    /** Same category, but further than this from Lichess' cloud evaluation (centipawns). */
    public const DRIFT_CP = 100;

    public function __construct(
        private TablebaseClient $tablebase,
        private CloudEvalClient $cloud,
    ) {
    }

    /**
     * @param string $fen    normalized FEN, move counters included
     * @param int    $evalCp the evaluation entered (centipawns, White's point of view)
     *
     * @return Check
     */
    public function verify(string $fen, Color $turn, int $evalCp): array
    {
        $stored = EvaluationRules::category($evalCp);
        $placement = explode(' ', $fen)[0];
        try {
            if (preg_match_all('/[pnbrqk]/i', $placement) <= TablebaseClient::MAX_PIECES) {
                $result = $this->tablebase->result($fen);
                if (null === $result) {
                    return ['verdict' => 'unverified', 'source' => 'tablebase', 'lichess' => null, 'lichessCategory' => null];
                }
                // The tablebase speaks for the side to move.
                $sign = Color::White === $turn ? 1 : -1;
                $category = match ($result) {
                    'win' => 2 * $sign,
                    'loss' => -2 * $sign,
                    default => 0,
                };

                return ['verdict' => $category === $stored ? 'ok' : 'mismatch', 'source' => 'tablebase', 'lichess' => $result, 'lichessCategory' => $category];
            }

            $answer = $this->cloud->evaluate(implode(' ', \array_slice(explode(' ', $fen), 0, 4)), 1);
            $line = $answer['lines'][0] ?? null;
            if (!$answer['found'] || null === $line || (null === $line['cp'] && null === $line['mate'])) {
                return ['verdict' => 'unverified', 'source' => 'cloud', 'lichess' => null, 'lichessCategory' => null];
            }
            // Lichess evaluations are from White's point of view.
            $cp = null !== $line['mate'] ? ($line['mate'] > 0 ? EvaluationRules::WON_CP : -EvaluationRules::WON_CP) : (int) $line['cp'];
            $category = EvaluationRules::category($cp);
            $label = null !== $line['mate'] ? \sprintf('mate %d', $line['mate']) : EvaluationRules::label($cp).' (depth '.($answer['depth'] ?? '?').')';
            $verdict = match (true) {
                $category !== $stored => 'mismatch',
                abs($cp) < EvaluationRules::WON_CP && abs($evalCp) < EvaluationRules::WON_CP && abs($cp - $evalCp) > self::DRIFT_CP => 'drift',
                default => 'ok',
            };

            return ['verdict' => $verdict, 'source' => 'cloud', 'lichess' => $label, 'lichessCategory' => $category];
        } catch (LichessUnavailableException) {
            return ['verdict' => 'unavailable', 'source' => '-', 'lichess' => null, 'lichessCategory' => null];
        }
    }
}
