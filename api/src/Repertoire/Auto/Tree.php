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
 * @phpstan-type Line array{path: string, cut: string, name: string, nodes: list<string>, games: int, wins: int, draws: int, losses: int, analysed: int, errored: int, fen: string}
 *                                cut: the node to remove to remove this line (the reply where it leaves the others); games...errored: the games that played the whole line (or as far as the user played it)
 */
final class Tree
{
    /** @var array<string, TreeNode> by path, in depth-first order */
    public array $nodes = [];
    /** @var list<Line> */
    public array $lines = [];
    /** Plies before the first branch: what every line has in common. */
    public int $trunk = 0;
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
     * Computes the lines, once every node is in.
     *
     * @param list<GameRecord> $games the family's games (their opening names name the lines)
     */
    public function finish(array $games): void
    {
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
            ];
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
