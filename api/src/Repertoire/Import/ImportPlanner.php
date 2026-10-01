<?php

declare(strict_types=1);

namespace App\Repertoire\Import;

use App\Chess\Rules;
use App\Enum\Repertoire\Color;
use App\Enum\Repertoire\MoveRole;

/**
 * Plans an analysed import ({@see ImportedTree}) against the repertoire it goes into
 * ({@see Target}), with the user's choices: pure, used by the preview and by the application.
 * docs/REPERTOIRE.md, "Import".
 *
 * The repertoire and the file are walked together from the initial position:
 *
 * - where the user is to move, one move only is kept (a position holds one prepared move). When
 *   the repertoire's move and the file's moves differ, or the file has several, that is a
 *   conflict: the choice is kept, by default the repertoire's move, else the file's first one. The
 *   other file moves are not imported; a replaced move of the repertoire goes to the trash with
 *   everything only reachable through it;
 * - opponent moves are replies: the file's are added after the existing ones, in file order;
 * - a move already in the repertoire keeps its place and annotations; the file only fills an empty
 *   comment or empty NAGs;
 * - a chapter starting from a position the walk does not reach is left out, with a warning, and so
 *   is a move that would close a cycle.
 *
 * @phpstan-import-type ImportedEdge from ImportedTree
 * @phpstan-import-type TargetMove from Target
 * @phpstan-import-type Candidate from ImportPlan
 */
final class ImportPlanner
{
    /**
     * @param array<string, string> $choices normalized FEN => UCI of the move chosen where it conflicts
     */
    public function plan(ImportedTree $tree, Color $color, Target $target, array $choices = []): ImportPlan
    {
        $plan = new ImportPlan();
        $plan->warnings = $tree->warnings;
        $userTurn = $color->turn();
        $initial = Rules::initial()->normalizedFen();

        /** @var array<string, array<string, TargetMove>> $existing from FEN => UCI => move */
        $existing = [];
        /** @var array<string, list<string>> $next from FEN => to FENs (cycle checks) */
        $next = [];
        foreach ($target->moves as $move) {
            $existing[$move['from']][$move['uci']] = $move;
            $next[$move['from']][] = $move['to'];
        }
        foreach ($existing as &$moves) {
            uasort($moves, static fn (array $a, array $b): int => [$a['sortOrder'], $a['id']] <=> [$b['sortOrder'], $b['id']]);
        }
        unset($moves);
        /** @var array<string, list<ImportedEdge>> $file from FEN => its edges, file order */
        $file = [];
        foreach ($tree->edges as $edge) {
            $file[$edge['from']][] = $edge;
        }

        $paths = [$initial => []];
        $queue = [$initial];
        /** @var list<ImportedEdge> $accepted */
        $accepted = [];
        for ($head = 0; $head < \count($queue); ++$head) {
            $fen = $queue[$head];
            $current = $existing[$fen] ?? [];
            $fileMoves = $file[$fen] ?? [];

            if (self::turn($fen) === $userTurn) {
                $prepared = array_values($current)[0] ?? null;
                /** @var array<string, Candidate> $candidates */
                $candidates = [];
                if (null !== $prepared) {
                    $candidates[$prepared['uci']] = ['uci' => $prepared['uci'], 'san' => $prepared['san'], 'origin' => 'existing'];
                }
                foreach ($fileMoves as $edge) {
                    if (isset($candidates[$edge['uci']])) {
                        $candidates[$edge['uci']]['origin'] = 'both';
                    } else {
                        $candidates[$edge['uci']] = ['uci' => $edge['uci'], 'san' => $edge['san'], 'origin' => 'file'];
                    }
                }
                if ([] === $candidates) {
                    continue;
                }
                $default = $prepared['uci'] ?? $fileMoves[0]['uci'];
                $choice = isset($choices[$fen], $candidates[$choices[$fen]]) ? $choices[$fen] : $default;
                if (\count($candidates) > 1) {
                    $plan->conflicts[] = ['fen' => $fen, 'path' => $paths[$fen] ?? [], 'candidates' => array_values($candidates), 'choice' => $choice];
                }
                if (null !== $prepared && $prepared['uci'] !== $choice) {
                    $plan->replaced[] = $prepared['id'];
                    $current = [];
                }
                $fileMoves = array_values(array_filter($fileMoves, static fn (array $e): bool => $e['uci'] === $choice));
            }

            // What the repertoire keeps from here, then what the file adds.
            foreach ($current as $move) {
                $this->visit($move['to'], [...$paths[$fen], $move['san']], $queue, $paths);
            }
            foreach ($fileMoves as $edge) {
                if (!isset($current[$edge['uci']])) {
                    if (isset($paths[$edge['to']]) && self::reaches($next, $edge['to'], $fen)) {
                        $plan->warnings[] = ['type' => ImportedTree::REPEATED_POSITION, 'game' => $edge['game'], 'move' => $edge['san']];
                        continue;
                    }
                    $next[$fen][] = $edge['to'];
                }
                $accepted[] = $edge;
                $this->visit($edge['to'], [...$paths[$fen], $edge['san']], $queue, $paths);
            }
        }

        foreach ($tree->starts as $start) {
            if (!isset($paths[$start['fen']])) {
                $plan->warnings[] = ['type' => ImportedTree::START_NOT_FOUND, 'game' => $start['game']];
            }
        }

        $this->build($plan, $accepted, $existing, $tree, $target, $userTurn, $paths);

        return $plan;
    }

    /**
     * Figures, new rows and annotation fills.
     *
     * @param list<ImportedEdge>                       $accepted
     * @param array<string, array<string, TargetMove>> $existing
     * @param array<string, list<string>>              $reached  positions of the repertoire once imported
     */
    private function build(ImportPlan $plan, array $accepted, array $existing, ImportedTree $tree, Target $target, string $userTurn, array $reached): void
    {
        $initial = Rules::initial()->normalizedFen();
        $filePositions = [$initial => true];
        $continued = [];
        $sortOrders = [];
        foreach ($accepted as $edge) {
            $filePositions[$edge['from']] = $filePositions[$edge['to']] = $continued[$edge['from']] = true;
            $move = $existing[$edge['from']][$edge['uci']] ?? null;
            if (null !== $move) {
                ++$plan->knownMoves;
                $fill = [];
                if (null === $move['comment'] && null !== $edge['comment']) {
                    $fill['comment'] = $edge['comment'];
                }
                if ([] === $move['nags'] && [] !== $edge['nags']) {
                    $fill['nags'] = $edge['nags'];
                }
                if ([] !== $fill) {
                    $plan->fills[$move['id']] = $fill;
                }
                continue;
            }
            ++$plan->newMoves;
            $user = self::turn($edge['from']) === $userTurn;
            if (!isset($sortOrders[$edge['from']])) {
                $orders = array_column($existing[$edge['from']] ?? [], 'sortOrder');
                $sortOrders[$edge['from']] = $user || [] === $orders ? 0 : max($orders) + 1;
            }
            $plan->moves[] = [
                'from' => $edge['from'],
                'to' => $edge['to'],
                'uci' => $edge['uci'],
                'san' => $edge['san'],
                'role' => $user ? MoveRole::Reference->value : MoveRole::Reply->value,
                'sortOrder' => $sortOrders[$edge['from']]++,
                'comment' => $edge['comment'],
                'nags' => $edge['nags'],
            ];
            if (!isset($target->positions[$edge['to']])) {
                $plan->positions[$edge['to']] ??= $tree->positions[$edge['to']];
            }
        }

        $ends = [];
        foreach ($accepted as $edge) {
            if (!isset($continued[$edge['to']])) {
                $ends[$edge['to']] = true;
            }
        }
        $plan->lines = \count($ends);
        $plan->filePositions = \count($filePositions);
        $plan->newPositions = \count($plan->positions);
        $plan->positionsAfter = \count($reached);
        $plan->trashedPositions = \count(array_diff_key($target->positions, $reached));
    }

    /**
     * @param list<string>                $path
     * @param list<string>                $queue
     * @param array<string, list<string>> $paths
     */
    private function visit(string $fen, array $path, array &$queue, array &$paths): void
    {
        if (!isset($paths[$fen])) {
            $paths[$fen] = $path;
            $queue[] = $fen;
        }
    }

    /**
     * @param array<string, list<string>> $next
     */
    private static function reaches(array $next, string $from, string $to): bool
    {
        $stack = [$from];
        $seen = [];
        while ([] !== $stack) {
            $current = array_pop($stack);
            if ($current === $to) {
                return true;
            }
            if (isset($seen[$current])) {
                continue;
            }
            $seen[$current] = true;
            array_push($stack, ...($next[$current] ?? []));
        }

        return false;
    }

    private static function turn(string $fen): string
    {
        return explode(' ', $fen)[1] ?? 'w';
    }
}
