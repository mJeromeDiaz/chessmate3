<?php

declare(strict_types=1);

namespace App\Repertoire\Auto;

use App\Enum\Repertoire\Color;

/**
 * The repertoire of one opening family built from the user's games ({@see TreeBuilder}): its
 * moves with their statistics, and its lines (a line = the path from the initial position to a
 * leaf), most played first.
 *
 * A node is identified by its path: the UCI moves from the initial position, joined by spaces.
 *
 * @phpstan-type Alternative array{uci: string, san: string, games: int, wins: int, draws: int, losses: int}
 * @phpstan-type TreeNode array{path: string, parent: string, depth: int, uci: string, san: string, fen: string, user: bool, games: int, wins: int, draws: int, losses: int, analysed: int, errored: int, think: int|null, moveAnalysed: int, moveErrors: int, suggested: bool, suggestion: array{uci: string, san: string}|null, alternatives: list<Alternative>}
 *                                games...errored: the games that reached the position (errored: analysed ones where the user made a mistake on the way);
 *                                think: the user's average thinking time on this move (centiseconds); moveAnalysed, moveErrors: analysed games that played it, and those where it was a mistake;
 *                                suggested: Lichess' best move the user chose instead of theirs (never played: no games); suggestion: Lichess' best move instead of this one, when it was a mistake;
 *                                alternatives: the user's other moves in the position before
 * @phpstan-type Stop array{kind: string, san: string|null, path: string|null}
 *                                why a line ends: reply_uncovered or no_user_move (a popular reply the user did not answer: san, path of the pending), no_popular_reply (the explorer gives none past its thresholds), unexplored (not asked yet), unplayed (a move the user chose but never played), depth (the ply limit)
 * @phpstan-type Line array{path: string, cut: string, name: string, nodes: list<string>, games: int, wins: int, draws: int, losses: int, analysed: int, errored: int, fen: string, depth: int, short: bool, stop: Stop}
 *                                cut: the node to remove to remove this line (the reply where it leaves the others); games...errored: the games that played the whole line (or as far as the user played it);
 *                                depth: its plies; short: under Limits::$autoShortLinePlies (kept once the user confirmed it), unless the ply limit cut it
 * @phpstan-type PendingNode array{path: string, parent: string, depth: int, fen: string, uci: string, san: string, sans: list<string>, reason: string, games: int|null, share: float|null, score: int|null}
 *                                a position where the user is to move, reached by a popular reply (uci, san), without a move from their games: path from the initial position (UCI node path, SAN moves);
 *                                games, share (%) and score (the user's, %) of that reply on Lichess; reason: reply_uncovered, no_user_move, short_line (a short line the user set aside)
 * @phpstan-type FrontierNode array{path: string, fen: string, depth: int}
 */
final class Tree
{
    /** A popular reply never met in the user's games. */
    public const REPLY_UNCOVERED = 'reply_uncovered';
    /** A popular reply met, without a move of the user's played often enough after it. */
    public const NO_USER_MOVE = 'no_user_move';
    /** A short line the user set aside rather than keep. */
    public const SHORT_LINE = 'short_line';

    /** @var array<string, TreeNode> by path, in depth-first order */
    public array $nodes = [];
    /** @var list<Line> */
    public array $lines = [];
    /** Plies before the first branch: what every line has in common. */
    public int $trunk = 0;
    /** @var list<PendingNode> by FEN (a transposition once), most popular first in each position */
    public array $pending = [];
    /** @var list<FrontierNode> the positions where the opponent is to move not asked about yet, shallowest first */
    public array $frontier = [];
    /** @var array<string, true> */
    private array $pendingFens = [];
    /** @var array<string, list<string>> path => children paths */
    private array $children = [];

    /**
     * @param array{games: int, wins: int, draws: int, losses: int, analysed: int, errored: int} $root the family's games
     */
    public function __construct(
        public readonly Color $color,
        public readonly array $root,
    ) {
    }

    /**
     * @param TreeNode $node
     */
    public function add(array $node): void
    {
        $this->nodes[$node['path']] = $node;
        $this->children[$node['parent']][] = $node['path'];
    }

    /**
     * @param PendingNode $pending
     */
    public function addPending(array $pending): void
    {
        if (isset($this->pendingFens[$pending['fen']])) {
            return;
        }
        $this->pendingFens[$pending['fen']] = true;
        $this->pending[] = $pending;
    }

    public function addFrontier(string $path, string $fen, int $depth): void
    {
        $this->frontier[] = ['path' => $path, 'fen' => $fen, 'depth' => $depth];
    }

    /**
     * The SAN moves from the initial position to a node of the tree.
     *
     * @return list<string>
     */
    public function sanPath(string $path): array
    {
        $sans = [];
        for ($at = $path; '' !== $at && isset($this->nodes[$at]); $at = $this->nodes[$at]['parent']) {
            array_unshift($sans, $this->nodes[$at]['san']);
        }

        return $sans;
    }

    /**
     * Computes the lines, once every node is in.
     *
     * @param list<GameRecord> $games      the family's games (their opening names name the lines)
     * @param int              $maxPlies   the ply limit of the lines
     * @param int              $shortPlies a line shorter than this asks for a confirmation
     */
    public function finish(array $games, int $maxPlies = \PHP_INT_MAX, int $shortPlies = 0): void
    {
        $nodesGames = array_map(static fn (array $node): int => $node['games'], $this->nodes);
        usort($this->frontier, static fn (array $a, array $b): int => [$a['depth'], $nodesGames[$b['path']] ?? 0, $a['path']] <=> [$b['depth'], $nodesGames[$a['path']] ?? 0, $b['path']]);
        $frontier = array_flip(array_column($this->frontier, 'path'));
        /** @var array<string, PendingNode> $stops the first (most popular) pending after each position */
        $stops = [];
        foreach ($this->pending as $pending) {
            $stops[$pending['parent']] ??= $pending;
        }

        $this->lines = [];
        $this->trunk = 0;
        for ($path = ''; 1 === \count($this->children[$path] ?? []); $path = $this->children[$path][0]) {
            ++$this->trunk;
        }

        $gamePaths = array_map(static fn (GameRecord $game): string => implode(' ', $game->moves), $games);
        foreach ($this->nodes as $path => $node) {
            if (isset($this->children[$path])) {
                continue;
            }
            $chain = [];
            for ($at = (string) $path; '' !== $at; $at = $this->nodes[$at]['parent']) {
                array_unshift($chain, $at);
            }
            $cut = $chain[0];
            foreach ($chain as $at) {
                if (\count($this->children[$this->nodes[$at]['parent']] ?? []) > 1) {
                    $cut = $at;
                }
            }
            $played = $node;
            foreach (array_reverse($chain) as $at) {
                if ($this->nodes[$at]['games'] > 0) {
                    $played = $this->nodes[$at];
                    break;
                }
            }
            $this->lines[] = [
                'path' => (string) $path,
                'cut' => $cut,
                'name' => self::name($games, $gamePaths, $played['path']),
                'nodes' => $chain,
                'games' => $played['games'],
                'wins' => $played['wins'],
                'draws' => $played['draws'],
                'losses' => $played['losses'],
                'analysed' => $played['analysed'],
                'errored' => $played['errored'],
                'fen' => $node['fen'],
                'depth' => $node['depth'],
                'short' => false,
                'stop' => ['kind' => 'unexplored', 'san' => null, 'path' => null],
            ];
            $stop = match (true) {
                $node['suggested'] => ['kind' => 'unplayed', 'san' => null, 'path' => null],
                $node['depth'] >= $maxPlies => ['kind' => 'depth', 'san' => null, 'path' => null],
                isset($stops[$path]) => ['kind' => $stops[$path]['reason'], 'san' => $stops[$path]['san'], 'path' => $stops[$path]['path']],
                isset($frontier[$path]) || !$node['user'] => ['kind' => 'unexplored', 'san' => null, 'path' => null],
                default => ['kind' => 'no_popular_reply', 'san' => null, 'path' => null],
            };
            $last = array_key_last($this->lines);
            $this->lines[$last]['stop'] = $stop;
            $this->lines[$last]['short'] = 'depth' !== $stop['kind'] && $node['depth'] < $shortPlies;
        }
    }

    /**
     * The line's name: the opening name Lichess gave most of the games that played it, without the
     * family ("Najdorf Variation, English Attack"), or the family's when they all share it.
     *
     * @param list<GameRecord> $games
     * @param list<string>     $gamePaths their moves joined by spaces
     */
    private static function name(array $games, array $gamePaths, string $path): string
    {
        $names = [];
        foreach ($gamePaths as $i => $gamePath) {
            if ($gamePath === $path || str_starts_with($gamePath, $path.' ')) {
                $names[$games[$i]->opening] = ($names[$games[$i]->opening] ?? 0) + 1;
            }
        }
        if ([] === $names) {
            return '' === $path || [] === $games ? '' : $games[0]->family;
        }
        uksort($names, static fn (string $a, string $b): int => [$names[$b], $a] <=> [$names[$a], $b]);
        $name = (string) array_key_first($names);
        $parts = explode(':', $name, 2);

        return isset($parts[1]) && '' !== trim($parts[1]) ? trim($parts[1]) : trim($parts[0]);
    }
}
