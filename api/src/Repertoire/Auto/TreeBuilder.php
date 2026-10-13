<?php

declare(strict_types=1);

namespace App\Repertoire\Auto;

use App\Chess\Rules;
use App\Enum\Repertoire\Color;
use App\Repertoire\Limits;

/**
 * Builds the repertoire of one opening family from the user's games (docs/REPERTOIRE.md,
 * "Repertoire from my games"). Pure: the games, the user's choices and the lines they removed in,
 * a {@see Tree} out.
 *
 * - The games are merged move by move (a trie of UCI sequences from the initial position), with
 *   their statistics: games, results, the user's thinking time, Lichess' judgments.
 * - Where the user is to move, one move (a repertoire has exactly one prepared move): their most
 *   played one, or the one they chose among the moves they played or the best move Lichess gave
 *   instead of a mistake of theirs (the line then stops there: they never played on from it).
 * - Where the opponent is to move, every reply played at least {@see Limits::$autoMinGames} times.
 * - At most {@see Limits::$autoMaxPlies} plies; a removed node is left out with what follows it.
 *
 * @phpstan-type NodeStats array{games: int, wins: int, draws: int, losses: int, analysed: int, errored: int}
 * @phpstan-type EdgeStats array{games: int, thinkSum: int, thinkCount: int, analysed: int, errors: int, best: array<string, int>}
 */
final readonly class TreeBuilder
{
    public function __construct(
        private Limits $limits,
    ) {
    }

    /**
     * @param list<GameRecord>      $games   the family's games (one color)
     * @param array<string, string> $choices node path (the position, as UCI moves joined by spaces) => the user's move there
     * @param list<string>          $removed node paths left out, with what follows them
     */
    public function build(array $games, Color $color, array $choices = [], array $removed = []): Tree
    {
        $userParity = Color::White === $color ? 0 : 1;
        /** @var array<string, NodeStats> $nodes */
        $nodes = [];
        /** @var array<string, array<string, EdgeStats>> $edges */
        $edges = [];
        foreach ($games as $game) {
            $path = '';
            $errored = false;
            self::count($nodes, $path, $game, $errored);
            foreach ($game->moves as $ply => $uci) {
                $edges[$path][$uci] ??= ['games' => 0, 'thinkSum' => 0, 'thinkCount' => 0, 'analysed' => 0, 'errors' => 0, 'best' => []];
                ++$edges[$path][$uci]['games'];
                if ($ply % 2 === $userParity) {
                    $think = $game->thinks[$ply] ?? null;
                    if (null !== $think) {
                        $edges[$path][$uci]['thinkSum'] += $think;
                        ++$edges[$path][$uci]['thinkCount'];
                    }
                    if ($game->analysed) {
                        ++$edges[$path][$uci]['analysed'];
                        if ($game->errorAt($ply)) {
                            ++$edges[$path][$uci]['errors'];
                            $errored = true;
                            $best = $game->bestAt($ply);
                            if (null !== $best && $best !== $uci) {
                                $edges[$path][$uci]['best'][$best] = ($edges[$path][$uci]['best'][$best] ?? 0) + 1;
                            }
                        }
                    }
                }
                $path = self::childPath($path, $uci);
                self::count($nodes, $path, $game, $errored);
            }
        }

        $tree = new Tree($color, $nodes[''] ?? ['games' => 0, 'wins' => 0, 'draws' => 0, 'losses' => 0, 'analysed' => 0, 'errored' => 0]);
        if (isset($nodes[''])) {
            $initial = Rules::initial()->normalizedFen();
            $this->grow($tree, $nodes, $edges, '', $initial, [$initial => true], 0, $userParity, $choices, array_flip($removed));
        }
        $tree->finish($games);

        return $tree;
    }

    /**
     * @param array<string, NodeStats>                $nodes
     * @param array<string, array<string, EdgeStats>> $edges
     * @param array<string, true>                     $seen    the positions of the line so far (a move back to one ends it: a repertoire is acyclic)
     * @param array<string, string>                   $choices
     * @param array<string, int>                      $removed
     */
    private function grow(Tree $tree, array $nodes, array $edges, string $path, string $fen, array $seen, int $depth, int $userParity, array $choices, array $removed): void
    {
        if ($depth >= $this->limits->autoMaxPlies || !isset($edges[$path])) {
            return;
        }
        $played = $edges[$path];
        uksort($played, static fn (string $a, string $b): int => [$played[$b]['games'], $a] <=> [$played[$a]['games'], $b]);

        if ($depth % 2 !== $userParity) {
            foreach ($played as $uci => $edge) {
                $child = self::childPath($path, (string) $uci);
                if ($edge['games'] < $this->limits->autoMinGames || isset($removed[$child])) {
                    continue;
                }
                $childFen = $this->add($tree, $path, $fen, $seen, $child, (string) $uci, $depth, $nodes[$child], $edge, false, null, []);
                if (null !== $childFen) {
                    $this->grow($tree, $nodes, $edges, $child, $childFen, $seen + [$childFen => true], $depth + 1, $userParity, $choices, $removed);
                }
            }

            return;
        }

        // The user's move: the most played, or their choice among what they played and Lichess'
        // best moves instead of their mistakes here.
        $suggested = [];
        foreach ($played as $edge) {
            foreach (array_keys($edge['best']) as $best) {
                $suggested[(string) $best] = true;
            }
        }
        $chosen = (string) array_key_first($played);
        $choice = $choices[$path] ?? null;
        if (null !== $choice && (isset($played[$choice]) || isset($suggested[$choice]))) {
            $chosen = $choice;
        }
        $child = self::childPath($path, $chosen);
        if (isset($removed[$child])) {
            return;
        }
        $edge = $played[$chosen] ?? null;
        $alternatives = [];
        foreach ($played as $uci => $other) {
            if ((string) $uci !== $chosen && $other['games'] >= $this->limits->autoAlternativeMinGames) {
                $alternatives[(string) $uci] = $nodes[self::childPath($path, (string) $uci)];
            }
        }
        $suggestion = null;
        if (null !== $edge && [] !== $edge['best']) {
            $best = $edge['best'];
            uksort($best, static fn (string $a, string $b): int => [$best[$b], $a] <=> [$best[$a], $b]);
            $suggestion = (string) array_key_first($best);
        }
        $childFen = $this->add($tree, $path, $fen, $seen, $child, $chosen, $depth, $nodes[$child] ?? null, $edge, null === $edge, $suggestion, $alternatives);
        if (null !== $childFen && null !== $edge) {
            $this->grow($tree, $nodes, $edges, $child, $childFen, $seen + [$childFen => true], $depth + 1, $userParity, $choices, $removed);
        }
    }

    /**
     * @param array<string, true>      $seen
     * @param NodeStats|null           $stats        the position reached (null: a move the user never played)
     * @param EdgeStats|null           $edge
     * @param array<string, NodeStats> $alternatives the user's other moves here
     *
     * @return string|null the position reached, null when the move is not legal there or goes back to a position of the line
     */
    private function add(Tree $tree, string $parent, string $fen, array $seen, string $path, string $uci, int $depth, ?array $stats, ?array $edge, bool $suggested, ?string $suggestion, array $alternatives): ?string
    {
        $rules = Rules::fromFen($fen);
        $move = $rules->playUci($uci);
        if (null === $move) {
            return null;
        }
        $childFen = $rules->normalizedFen();
        if (isset($seen[$childFen])) {
            return null;
        }

        $sans = [];
        foreach ([...array_keys($alternatives), ...(null === $suggestion ? [] : [$suggestion])] as $other) {
            $san = Rules::fromFen($fen)->playUci((string) $other)?->san;
            if (null !== $san) {
                $sans[(string) $other] = (string) $san;
            }
        }
        $alternativeList = [];
        foreach ($alternatives as $other => $otherStats) {
            if (isset($sans[(string) $other])) {
                $alternativeList[] = ['uci' => (string) $other, 'san' => $sans[(string) $other], 'games' => $otherStats['games'], 'wins' => $otherStats['wins'], 'draws' => $otherStats['draws'], 'losses' => $otherStats['losses']];
            }
        }

        $tree->add([
            'path' => $path,
            'parent' => $parent,
            'depth' => $depth + 1,
            'uci' => $uci,
            'san' => (string) $move->san,
            'fen' => $childFen,
            'user' => $depth % 2 === (Color::White === $tree->color ? 0 : 1),
            'games' => $stats['games'] ?? 0,
            'wins' => $stats['wins'] ?? 0,
            'draws' => $stats['draws'] ?? 0,
            'losses' => $stats['losses'] ?? 0,
            'analysed' => $stats['analysed'] ?? 0,
            'errored' => $stats['errored'] ?? 0,
            'think' => null !== $edge && $edge['thinkCount'] > 0 ? intdiv($edge['thinkSum'], $edge['thinkCount']) : null,
            'moveAnalysed' => $edge['analysed'] ?? 0,
            'moveErrors' => $edge['errors'] ?? 0,
            'suggested' => $suggested,
            'suggestion' => null !== $suggestion && isset($sans[$suggestion]) ? ['uci' => $suggestion, 'san' => $sans[$suggestion]] : null,
            'alternatives' => $alternativeList,
        ]);

        return $childFen;
    }

    /**
     * @param array<string, NodeStats> $nodes
     */
    private static function count(array &$nodes, string $path, GameRecord $game, bool $errored): void
    {
        $nodes[$path] ??= ['games' => 0, 'wins' => 0, 'draws' => 0, 'losses' => 0, 'analysed' => 0, 'errored' => 0];
        ++$nodes[$path]['games'];
        ++$nodes[$path][match ($game->result) {
            1 => 'wins',
            0 => 'draws',
            default => 'losses',
        }];
        if ($game->analysed) {
            ++$nodes[$path]['analysed'];
            if ($errored) {
                ++$nodes[$path]['errored'];
            }
        }
    }

    private static function childPath(string $path, string $uci): string
    {
        return '' === $path ? $uci : $path.' '.$uci;
    }
}
