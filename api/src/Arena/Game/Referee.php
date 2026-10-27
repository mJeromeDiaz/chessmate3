<?php

declare(strict_types=1);

namespace App\Arena\Game;

use App\Chess\Rules;
use App\Enum\Arena\GameEnd;

/**
 * Replays a game of the arena (from the initial position) and says how it stands (docs/ARENA.md,
 * § 3): every move legal, then mate, stalemate, a position seen three times (castling rights and en
 * passant included, as the normalized FEN has them), fifty moves without a capture or a pawn move,
 * or material that cannot mate on either side. Pure.
 */
final class Referee
{
    /**
     * @param list<string> $moves UCI
     * @param string       $fen   the position they start from (the initial one in a game)
     *
     * @return array{legal: bool, end: GameEnd|null, fen: string} `legal` false at the first illegal
     *                                                             move (`fen`: the position before it)
     */
    public static function judge(array $moves, string $fen = Rules::INITIAL_FEN): array
    {
        $rules = Rules::fromFen($fen);
        $seen = [$rules->normalizedFen() => 1];
        foreach ($moves as $move) {
            if (null === $rules->playUci($move)) {
                return ['legal' => false, 'end' => null, 'fen' => $rules->fen()];
            }
            $key = $rules->normalizedFen();
            $seen[$key] = ($seen[$key] ?? 0) + 1;
        }
        $fen = $rules->fen();

        return ['legal' => true, 'end' => self::end($rules, $seen[$rules->normalizedFen()] ?? 1, $fen), 'fen' => $fen];
    }

    private static function end(Rules $rules, int $repetitions, string $fen): ?GameEnd
    {
        $noMove = [] === $rules->legalMoves();

        return match (true) {
            $noMove && $rules->inCheck() => GameEnd::Checkmate,
            $noMove => GameEnd::Stalemate,
            $rules->insufficientMaterial() => GameEnd::InsufficientMaterial,
            $repetitions >= 3 => GameEnd::Repetition,
            (int) (explode(' ', $fen)[4] ?? 0) >= 100 => GameEnd::FiftyMoves,
            default => null,
        };
    }
}
