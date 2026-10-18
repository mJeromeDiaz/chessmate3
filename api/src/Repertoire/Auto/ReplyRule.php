<?php

declare(strict_types=1);

namespace App\Repertoire\Auto;

use App\Enum\Repertoire\Color;
use App\Repertoire\Lichess\ExplorerClient;
use App\Repertoire\Limits;

/**
 * Which opponents' replies of the Lichess explorer count (docs/REPERTOIRE.md, "Repertoire from my
 * games"): at least {@see Limits::$autoReplyMinShare} % of the position's games and
 * {@see Limits::$autoReplyMinGames} games, the {@see Limits::$autoMaxReplies} most played. Frozen
 * in the analysis when its exploration starts ({@see self::toArray()}).
 *
 * An explorer answer is kept compact ({@see self::compact()}): the position's games and its moves
 * with their games, share (%) and results.
 *
 * @phpstan-import-type ExplorerAnswer from ExplorerClient
 *
 * @phpstan-type ReplyStat array{uci: string, san: string, games: int, share: float, white: int, draws: int, black: int}
 * @phpstan-type Replies array{total: int, moves: list<ReplyStat>}
 * @phpstan-type ReplyRuleArray array{minShare: float, minGames: int, maxReplies: int, preset: string}
 */
final readonly class ReplyRule
{
    public function __construct(
        public float $minShare,
        public int $minGames,
        public int $maxReplies,
        public string $preset = Limits::AUTO_EXPLORER_PRESET,
    ) {
    }

    public static function fromLimits(Limits $limits): self
    {
        return new self($limits->autoReplyMinShare, $limits->autoReplyMinGames, $limits->autoMaxReplies);
    }

    /**
     * @param array<mixed>|null $stored {@see self::toArray()}, null: not frozen yet
     */
    public static function fromStored(?array $stored, Limits $limits): self
    {
        if (null === $stored || !is_numeric($stored['minShare'] ?? null) || !\is_int($stored['minGames'] ?? null) || !\is_int($stored['maxReplies'] ?? null)) {
            return self::fromLimits($limits);
        }
        $preset = \is_string($stored['preset'] ?? null) && isset(Limits::EXPLORER_PRESETS[$stored['preset']]) ? $stored['preset'] : Limits::AUTO_EXPLORER_PRESET;

        return new self((float) $stored['minShare'], $stored['minGames'], $stored['maxReplies'], $preset);
    }

    /**
     * @return ReplyRuleArray
     */
    public function toArray(): array
    {
        return ['minShare' => $this->minShare, 'minGames' => $this->minGames, 'maxReplies' => $this->maxReplies, 'preset' => $this->preset];
    }

    /**
     * The replies kept in a position, most played first.
     *
     * @param Replies $replies
     *
     * @return list<ReplyStat>
     */
    public function popular(array $replies): array
    {
        $kept = array_values(array_filter(
            $replies['moves'],
            fn (array $move): bool => $move['games'] >= $this->minGames && $move['share'] >= $this->minShare,
        ));
        usort($kept, static fn (array $a, array $b): int => [$b['games'], $a['uci']] <=> [$a['games'], $b['uci']]);

        return \array_slice($kept, 0, max(0, $this->maxReplies));
    }

    /**
     * An explorer answer as kept by an analysis and repertoire_opening_stats.
     *
     * @param ExplorerAnswer $answer
     *
     * @return Replies
     */
    public static function compact(array $answer): array
    {
        $total = $answer['total'];
        $moves = [];
        foreach ($answer['moves'] as $move) {
            $moves[] = [
                'uci' => $move['uci'],
                'san' => $move['san'],
                'games' => $move['total'],
                'share' => $total > 0 ? round(100 * $move['total'] / $total, 1) : 0.0,
                'white' => $move['white'],
                'draws' => $move['draws'],
                'black' => $move['black'],
            ];
        }

        return ['total' => $total, 'moves' => $moves];
    }

    /**
     * Reads back {@see self::compact()} (null when malformed).
     *
     * @return Replies|null
     */
    public static function fromJson(mixed $stored): ?array
    {
        if (!\is_array($stored) || !\is_int($stored['total'] ?? null) || !\is_array($stored['moves'] ?? null)) {
            return null;
        }
        $moves = [];
        foreach ($stored['moves'] as $move) {
            if (!\is_array($move) || !\is_string($move['uci'] ?? null) || !\is_string($move['san'] ?? null) || !\is_int($move['games'] ?? null) || !is_numeric($move['share'] ?? null)) {
                continue;
            }
            $moves[] = [
                'uci' => $move['uci'],
                'san' => $move['san'],
                'games' => $move['games'],
                'share' => (float) $move['share'],
                'white' => \is_int($move['white'] ?? null) ? $move['white'] : 0,
                'draws' => \is_int($move['draws'] ?? null) ? $move['draws'] : 0,
                'black' => \is_int($move['black'] ?? null) ? $move['black'] : 0,
            ];
        }

        return ['total' => $stored['total'], 'moves' => $moves];
    }

    /**
     * The user's score (%) in the Lichess games that played a reply: wins and half the draws.
     *
     * @param ReplyStat $move
     */
    public static function score(array $move, Color $user): ?int
    {
        if ($move['games'] <= 0) {
            return null;
        }
        $wins = Color::White === $user ? $move['white'] : $move['black'];

        return (int) round(100 * ($wins + $move['draws'] / 2) / $move['games']);
    }
}
