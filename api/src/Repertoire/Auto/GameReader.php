<?php

declare(strict_types=1);

namespace App\Repertoire\Auto;

use App\Chess\Endgame\EndgameScanner;
use App\Chess\Rules;
use App\Enum\Repertoire\Color;

/**
 * Reads a game of the Lichess export (ndjson, one decoded object) into a {@see GameRecord}, or
 * nothing when it is no use to a repertoire: another variant or a starting position, not finished
 * (aborted...), the user not in it, no opening, fewer than two moves.
 *
 * The SAN moves are replayed (App\Chess\Rules) and kept as UCI up to the plies a repertoire looks
 * at: an illegal move ends the game there. The replay goes on to the end of the game for its
 * endgame (App\Chess\Endgame\EndgameScanner, the tailored session: docs/COACH.md). The user's
 * thinking time comes from the clocks (centiseconds left after each ply, plus the increment), their
 * mistakes, Lichess' best moves and the evaluation at the endgame from the computer analysis when
 * there is one.
 */
final class GameReader
{
    /** Lichess statuses of games that were not played to an end. */
    private const UNFINISHED = ['created', 'started', 'aborted', 'noStart', 'unknownFinish'];
    private const JUDGMENTS = ['Inaccuracy' => GameRecord::INACCURACY, 'Mistake' => GameRecord::MISTAKE, 'Blunder' => GameRecord::BLUNDER];
    private const UCI_PATTERN = '/^[a-h][1-8][a-h][1-8][qrbn]?$/';

    /**
     * @param array<mixed> $game     a game of GET /api/games/user/{username} (JSON, with moves, clocks, evals, opening)
     * @param string       $username the user's Lichess id (lowercase)
     */
    public static function read(array $game, string $username, int $maxPlies): ?GameRecord
    {
        $id = $game['id'] ?? null;
        $status = $game['status'] ?? null;
        $moves = $game['moves'] ?? null;
        if (!\is_string($id) || !\is_string($status) || !\is_string($moves) || 'standard' !== ($game['variant'] ?? null) || isset($game['initialFen']) || \in_array($status, self::UNFINISHED, true)) {
            return null;
        }
        $players = \is_array($game['players'] ?? null) ? $game['players'] : [];
        $color = match ($username) {
            self::playerId($players['white'] ?? null) => Color::White,
            self::playerId($players['black'] ?? null) => Color::Black,
            default => null,
        };
        $opening = \is_array($game['opening'] ?? null) && \is_string($game['opening']['name'] ?? null) ? trim($game['opening']['name']) : '';
        if (null === $color || '' === $opening) {
            return null;
        }

        $endgame = new EndgameScanner();
        $uci = self::replay($moves, $maxPlies, $endgame);
        if (\count($uci) < 2) {
            return null;
        }
        $winner = $game['winner'] ?? null;
        $user = $players[$color->value] ?? null;
        $opponent = $players[Color::White === $color ? 'black' : 'white'] ?? null;
        $opponent = \is_array($opponent) ? $opponent : [];
        $analysis = \is_array($game['analysis'] ?? null) ? $game['analysis'] : null;
        $sign = Color::White === $color ? 1 : -1;
        $endgamePly = $endgame->ply();
        $endgameEval = null === $analysis || null === $endgamePly ? null : self::evalAfter($analysis, $endgamePly);

        return new GameRecord(
            $id,
            $color,
            null === $winner ? 0 : ($winner === $color->value ? 1 : -1),
            \is_int($game['createdAt'] ?? null) ? $game['createdAt'] : 0,
            \is_string($game['perf'] ?? null) ? $game['perf'] : (\is_string($game['speed'] ?? null) ? $game['speed'] : ''),
            self::opponentName($opponent),
            \is_int($opponent['rating'] ?? null) ? $opponent['rating'] : null,
            trim(explode(':', $opening, 2)[0]),
            $opening,
            $uci,
            self::thinks($game, $color, \count($uci)),
            null === $analysis ? [] : self::judgments($analysis, $color, \count($uci)),
            null !== $analysis,
            \is_array($user) && \is_int($user['rating'] ?? null) ? $user['rating'] : null,
            $endgame->type(),
            $endgamePly,
            null === $endgame->balance() ? null : $sign * $endgame->balance(),
            null === $endgameEval ? null : $sign * $endgameEval,
        );
    }

    /**
     * Replays the whole game for its endgame.
     *
     * @return list<string> UCI moves, up to $maxPlies or the first illegal one
     */
    private static function replay(string $moves, int $maxPlies, EndgameScanner $endgame): array
    {
        $rules = Rules::initial();
        $uci = [];
        foreach (preg_split('/\s+/', trim($moves), -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $ply => $san) {
            $move = $rules->playSan($san);
            if (null === $move) {
                break;
            }
            $endgame->play($move);
            if ($ply < $maxPlies) {
                $uci[] = Rules::uci($move);
            }
        }

        return $uci;
    }

    /**
     * The engine's evaluation after $plies plies, White's point of view, in centipawns (a mate:
     * ±MATE_CP), if the analysis has it.
     *
     * @param array<mixed> $analysis one entry per ply
     */
    private static function evalAfter(array $analysis, int $plies): ?int
    {
        $entry = $analysis[$plies - 1] ?? null;
        if (!\is_array($entry)) {
            return null;
        }
        if (\is_int($entry['eval'] ?? null)) {
            return max(-GameRecord::MATE_CP, min(GameRecord::MATE_CP, $entry['eval']));
        }
        $mate = $entry['mate'] ?? null;

        return \is_int($mate) && 0 !== $mate ? ($mate > 0 ? GameRecord::MATE_CP : -GameRecord::MATE_CP) : null;
    }

    /**
     * The user's thinking time on each of their moves: the clock before the move (their previous
     * clock, or the initial time) minus the clock after it, plus the increment. The first move of
     * each side starts no clock: none there.
     *
     * @param array<mixed> $game
     *
     * @return list<int|null>
     */
    private static function thinks(array $game, Color $color, int $plies): array
    {
        $clocks = \is_array($game['clocks'] ?? null) ? $game['clocks'] : [];
        $clock = \is_array($game['clock'] ?? null) ? $game['clock'] : [];
        $increment = \is_int($clock['increment'] ?? null) ? $clock['increment'] * 100 : 0;
        $userParity = Color::White === $color ? 0 : 1;
        $thinks = [];
        for ($ply = 0; $ply < $plies; ++$ply) {
            $before = $clocks[$ply - 2] ?? null;
            $after = $clocks[$ply] ?? null;
            $thinks[] = $ply % 2 === $userParity && $ply >= 2 && \is_int($before) && \is_int($after) ? max(0, $before - $after + $increment) : null;
        }

        return $thinks;
    }

    /**
     * @param array<mixed> $analysis one entry per ply
     *
     * @return list<array{int, string, string|null}>
     */
    private static function judgments(array $analysis, Color $color, int $plies): array
    {
        $userParity = Color::White === $color ? 0 : 1;
        $judgments = [];
        for ($ply = $userParity; $ply < $plies; $ply += 2) {
            $entry = $analysis[$ply] ?? null;
            $name = \is_array($entry) && \is_array($entry['judgment'] ?? null) ? ($entry['judgment']['name'] ?? null) : null;
            if (!\is_array($entry) || !\is_string($name) || !isset(self::JUDGMENTS[$name])) {
                continue;
            }
            $best = $entry['best'] ?? null;
            $judgments[] = [$ply, self::JUDGMENTS[$name], \is_string($best) && 1 === preg_match(self::UCI_PATTERN, $best) ? $best : null];
        }

        return $judgments;
    }

    private static function playerId(mixed $player): ?string
    {
        $id = \is_array($player) && \is_array($player['user'] ?? null) ? ($player['user']['id'] ?? null) : null;

        return \is_string($id) ? strtolower($id) : null;
    }

    /**
     * @param array<mixed> $player
     */
    private static function opponentName(array $player): ?string
    {
        $name = \is_array($player['user'] ?? null) ? ($player['user']['name'] ?? null) : null;
        if (\is_string($name)) {
            return mb_substr($name, 0, 30);
        }

        return \is_int($player['aiLevel'] ?? null) ? 'Stockfish '.$player['aiLevel'] : null;
    }
}
