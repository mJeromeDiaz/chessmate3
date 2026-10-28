<?php

declare(strict_types=1);

namespace App\Chess;

use PChess\Chess\Chess;
use PChess\Chess\Move;
use PChess\Chess\Piece;

/**
 * The rules of chess for every domain, on top of p-chess (MIT), with what p-chess keeps protected
 * or does not offer:
 *
 * - a strictly checked position: one king per side, no pawn on the first or last rank, side not to
 *   move not in check; castling rights and the en passant square cleaned up before loading (p-chess
 *   would otherwise castle without a rook);
 * - the normalized FEN (docs/REPERTOIRE.md): placement, side to move, castling rights, en passant
 *   square only when an en passant capture is legal, no move counters. Two move orders reaching the
 *   same position give the same normalized FEN (transpositions);
 * - legal moves without the SAN work, moves played in UCI, and lenient SAN as found in PGN files
 *   ("0-0", "Ngf3" over-disambiguated, "e8Q", "exd6e.p.", missing or extra check marks).
 */
final class Rules extends Chess
{
    public const INITIAL_FEN = 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1';

    private const UCI_PATTERN = '/^([a-h][1-8])([a-h][1-8])([qrbn])?$/';
    private const CASTLING_PATTERN = '/^(O-O|O-O-O)$/';
    private const PIECE_PATTERN = '/^([KQRBN])([a-h])?([1-8])?[x:-]?([a-h][1-8])$/';
    private const PAWN_PATTERN = '/^(?:([a-h])([1-8])?[x:-]?)?([a-h][1-8])(?:=?\(?([QRBNqrbn])\)?)?$/';

    /**
     * @throws InvalidPositionException
     */
    public static function fromFen(string $fen): self
    {
        $cleaned = self::clean($fen);
        try {
            $rules = new self($cleaned);
        } catch (\InvalidArgumentException $e) {
            throw new InvalidPositionException($e->getMessage(), 0, $e);
        }
        if ($rules->kingAttacked(self::swapColor($rules->turn))) {
            throw new InvalidPositionException('The side not to move is in check.');
        }

        return $rules;
    }

    public static function initial(): self
    {
        return new self(self::INITIAL_FEN);
    }

    public function normalizedFen(): string
    {
        [$placement, $turn, $castling, $enPassant] = explode(' ', $this->fen());
        if ('-' !== $enPassant && !$this->hasLegalEnPassant()) {
            $enPassant = '-';
        }

        return implode(' ', [$placement, $turn, $castling, $enPassant]);
    }

    /** 'w' or 'b'. */
    public function sideToMove(): string
    {
        return $this->turn;
    }

    /**
     * @return list<Move> legal moves, SAN not computed
     */
    public function legalMoves(): array
    {
        return array_values($this->generateMoves());
    }

    /**
     * Plays a UCI move ("e2e4", "e7e8q"; the promotion piece is required) if it is legal. The
     * returned move carries its SAN, computed before the move.
     */
    public function playUci(string $uci): ?Move
    {
        if (1 !== preg_match(self::UCI_PATTERN, $uci, $m)) {
            return null;
        }
        $promotion = $m[3] ?? '';
        foreach ($this->legalMoves() as $move) {
            if ($move->from === $m[1] && $move->to === $m[2] && ($move->promotion ?? '') === $promotion) {
                return $this->play($move);
            }
        }

        return null;
    }

    /**
     * Plays a SAN move if it designates exactly one legal move (null when illegal or ambiguous).
     * Check marks, annotations and capture marks are not required to match.
     */
    public function playSan(string $san): ?Move
    {
        $san = str_replace('0', 'O', rtrim(trim($san), '+#!?'));
        if (str_ends_with($san, 'e.p.')) {
            $san = substr($san, 0, -4);
        }

        if (1 === preg_match(self::CASTLING_PATTERN, $san)) {
            $flag = 'O-O' === $san ? Move::BITS['KSIDE_CASTLE'] : Move::BITS['QSIDE_CASTLE'];
            $candidates = array_filter($this->legalMoves(), static fn (Move $move): bool => ($move->flags & $flag) > 0);
        } elseif (1 === preg_match(self::PIECE_PATTERN, $san, $m)) {
            $candidates = $this->candidates(strtolower($m[1]), $m[4], $m[2], $m[3], '');
        } elseif (1 === preg_match(self::PAWN_PATTERN, $san, $m)) {
            $candidates = $this->candidates(Piece::PAWN, $m[3], $m[1], $m[2], strtolower($m[4] ?? ''));
        } else {
            return null;
        }

        return 1 === \count($candidates) ? $this->play(array_values($candidates)[0]) : null;
    }

    public static function uci(Move $move): string
    {
        return $move->from.$move->to.($move->promotion ?? '');
    }

    /**
     * @return list<Move>
     */
    private function candidates(string $piece, string $to, string $fromFile, string $fromRank, string $promotion): array
    {
        return array_values(array_filter(
            $this->legalMoves(),
            static fn (Move $move): bool => $move->piece->getType() === $piece
                && $move->to === $to
                && ('' === $fromFile || $move->from[0] === $fromFile)
                && ('' === $fromRank || $move->from[1] === $fromRank)
                && ($move->promotion ?? '') === $promotion,
        ));
    }

    private function play(Move $move): Move
    {
        $this->moveToSAN($move);
        $this->makeMove($move);

        return $move;
    }

    private function hasLegalEnPassant(): bool
    {
        foreach ($this->legalMoves() as $move) {
            if (($move->flags & Move::BITS['EP_CAPTURE']) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks what p-chess does not and drops what it would misuse: castling rights without the king
     * and rook on their original squares, an en passant square without the pawn that just moved.
     * A 4-field FEN (no counters, as in EPD) is accepted.
     *
     * @throws InvalidPositionException
     */
    private static function clean(string $fen): string
    {
        $fields = preg_split('/\s+/', trim($fen)) ?: [];
        if (4 === \count($fields)) {
            array_push($fields, '0', '1');
        }
        if (6 !== \count($fields)) {
            throw new InvalidPositionException('A FEN has 6 fields (or 4 without the move counters).');
        }
        [$placement, $turn, $castling, $enPassant] = $fields;
        $board = self::board($placement);
        foreach (['K', 'k'] as $king) {
            if (1 !== substr_count($placement, $king)) {
                throw new InvalidPositionException('Each side has exactly one king.');
            }
        }
        if (1 === preg_match('/[pP]/', explode('/', $placement)[0].explode('/', $placement)[7])) {
            throw new InvalidPositionException('No pawn can stand on the first or last rank.');
        }

        $rights = '';
        foreach (['K' => ['e1' => 'K', 'h1' => 'R'], 'Q' => ['e1' => 'K', 'a1' => 'R'], 'k' => ['e8' => 'k', 'h8' => 'r'], 'q' => ['e8' => 'k', 'a8' => 'r']] as $right => $pieces) {
            $inPlace = array_filter($pieces, static fn (string $piece, string $square): bool => ($board[$square] ?? null) === $piece, \ARRAY_FILTER_USE_BOTH);
            if (str_contains($castling, $right) && \count($inPlace) === \count($pieces)) {
                $rights .= $right;
            }
        }
        $fields[2] = '' === $rights ? '-' : $rights;

        if (1 === preg_match('/^([a-h])([36])$/', $enPassant, $m)) {
            // The pawn that just moved two squares, with the squares it crossed empty.
            [$pawn, $pawnRank, $fromRank, $expected] = '6' === $m[2] ? ['p', '5', '7', 'w'] : ['P', '4', '2', 'b'];
            $valid = $turn === $expected
                && ($board[$m[1].$pawnRank] ?? null) === $pawn
                && !isset($board[$enPassant])
                && !isset($board[$m[1].$fromRank]);
            $fields[3] = $valid ? $enPassant : '-';
        }

        return implode(' ', $fields);
    }

    /**
     * @return array<string, string> square => FEN piece letter
     */
    private static function board(string $placement): array
    {
        $ranks = explode('/', $placement);
        if (8 !== \count($ranks)) {
            throw new InvalidPositionException('The placement has 8 ranks.');
        }
        $board = [];
        foreach ($ranks as $index => $rank) {
            $file = 0;
            foreach (str_split($rank) as $char) {
                if (ctype_digit($char)) {
                    $file += (int) $char;
                    continue;
                }
                if (1 !== preg_match('/^[prnbqkPRNBQK]$/', $char) || $file > 7) {
                    throw new InvalidPositionException('Invalid placement.');
                }
                $board[\chr(\ord('a') + $file).(8 - $index)] = $char;
                ++$file;
            }
            if (8 !== $file) {
                throw new InvalidPositionException('Each rank has 8 squares.');
            }
        }

        return $board;
    }
}
