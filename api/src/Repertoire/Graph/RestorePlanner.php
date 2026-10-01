<?php

declare(strict_types=1);

namespace App\Repertoire\Graph;

use App\Enum\Repertoire\Color;
use App\Enum\Repertoire\MoveRole;
use App\Repertoire\Import\Target;

/**
 * Plans the restoration of a trashed suite into the repertoire as it is now (docs/REPERTOIRE.md,
 * "Corbeille"). Pure. The suite is walked from its start position:
 *
 * - a move the repertoire already has is joined (and the walk goes on after it);
 * - where the user is to move and the repertoire prepares another move, that is a conflict: the
 *   user chooses; by default the suite's move at its start (restoring means it), the current move
 *   further on. A suite's move chosen replaces the current one, whose own suite goes to the trash;
 * - other moves are inserted with their ids, and so are their positions, unless the repertoire
 *   already has the position (the suite joins it);
 * - a move that would close a cycle is left out.
 *
 * @phpstan-import-type TrashEntry from TrashBin
 * @phpstan-import-type TargetMove from Target
 */
final class RestorePlanner
{
    /**
     * @param TrashEntry                      $entry
     * @param array<string, 'restored'|'current'> $choices normalized FEN => choice
     *
     * @throws \DomainException the suite's start position is no longer in the repertoire
     */
    public function plan(array $entry, Target $target, Color $color, array $choices = []): RestorePlan
    {
        $plan = new RestorePlan();
        if (!isset($target->positions[$entry['fromFen']])) {
            throw new \DomainException('start_missing');
        }

        // The suite: FEN of every position it knows, its moves by starting FEN.
        $fens = $entry['rows']['external'] ?? [];
        $positionRows = [];
        foreach ($entry['rows']['positions'] as $row) {
            $fens[self::str($row['id'])] = self::str($row['fen']);
            $positionRows[self::str($row['fen'])] = $row;
        }
        /** @var array<string, list<array<string, mixed>>> $suite */
        $suite = [];
        foreach ($entry['rows']['moves'] as $row) {
            $from = $fens[self::str($row['from_position_id'])] ?? null;
            if (null !== $from && isset($fens[self::str($row['to_position_id'])])) {
                $suite[$from][] = $row;
            }
        }
        foreach ($suite as &$moves) {
            usort($moves, static fn (array $a, array $b): int => [self::int($a['sort_order']), self::str($a['id'])] <=> [self::int($b['sort_order']), self::str($b['id'])]);
        }
        unset($moves);

        // The repertoire: position ids, moves by starting FEN, adjacency (cycles).
        $ids = $target->positions;
        /** @var array<string, array<string, TargetMove>> $current */
        $current = [];
        /** @var array<string, list<string>> $next */
        $next = [];
        foreach ($target->moves as $move) {
            $current[$move['from']][$move['uci']] = $move;
            $next[$move['from']][] = $move['to'];
        }
        $paths = $this->paths($target, $entry);
        $userTurn = $color->turn();

        $queue = [$entry['fromFen']];
        $seen = [$entry['fromFen'] => true];
        for ($head = 0; $head < \count($queue); ++$head) {
            $fen = $queue[$head];
            foreach ($suite[$fen] ?? [] as $row) {
                $uci = self::str($row['uci']);
                $to = $fens[self::str($row['to_position_id'])];
                if (isset($current[$fen][$uci])) {
                    ++$plan->joined;
                    $this->visit($to, $queue, $seen);
                    continue;
                }
                if (self::turn($fen) === $userTurn) {
                    $prepared = array_values(array_filter($current[$fen] ?? [], static fn (array $m): bool => MoveRole::Reference->value === $m['role']))[0] ?? null;
                    if (null !== $prepared) {
                        $choice = $choices[$fen] ?? ($fen === $entry['fromFen'] ? 'restored' : 'current');
                        $plan->conflicts[] = [
                            'fen' => $fen,
                            'path' => $paths[$fen] ?? [],
                            'restored' => ['uci' => $uci, 'san' => self::str($row['san'])],
                            'current' => ['uci' => $prepared['uci'], 'san' => $prepared['san']],
                            'choice' => $choice,
                        ];
                        if ('current' === $choice) {
                            ++$plan->leftOut;
                            continue;
                        }
                        $plan->replaced[] = $prepared['id'];
                        unset($current[$fen][$prepared['uci']]);
                    }
                }
                if (isset($ids[$to]) && self::reaches($next, $to, $fen)) {
                    ++$plan->leftOut;
                    continue;
                }
                if (!isset($ids[$to])) {
                    $position = $positionRows[$to];
                    $ids[$to] = self::str($position['id']);
                    $plan->positions[] = $position;
                }
                $plan->moves[] = ['from_position_id' => $ids[$fen], 'to_position_id' => $ids[$to]] + $row;
                $current[$fen][$uci] = ['id' => self::str($row['id']), 'from' => $fen, 'to' => $to, 'uci' => $uci, 'san' => self::str($row['san']), 'role' => self::str($row['role']), 'sortOrder' => 0, 'comment' => null, 'nags' => []];
                $next[$fen][] = $to;
                $this->visit($to, $queue, $seen);
            }
        }

        return $plan;
    }

    /**
     * @param list<string>        $queue
     * @param array<string, true> $seen
     */
    private function visit(string $fen, array &$queue, array &$seen): void
    {
        if (!isset($seen[$fen])) {
            $seen[$fen] = true;
            $queue[] = $fen;
        }
    }

    /**
     * SAN moves from the initial position to each position (the trashed path to the start, then
     * breadth first through the repertoire and the suite), for the conflicts' display.
     *
     * @param TrashEntry $entry
     *
     * @return array<string, list<string>>
     */
    private function paths(Target $target, array $entry): array
    {
        $fens = $entry['rows']['external'] ?? [];
        foreach ($entry['rows']['positions'] as $row) {
            $fens[self::str($row['id'])] = self::str($row['fen']);
        }
        /** @var array<string, list<array{string, string}>> $next */
        $next = [];
        foreach ($target->moves as $move) {
            $next[$move['from']][] = [$move['to'], $move['san']];
        }
        foreach ($entry['rows']['moves'] as $row) {
            $from = $fens[self::str($row['from_position_id'])] ?? null;
            $to = $fens[self::str($row['to_position_id'])] ?? null;
            if (null !== $from && null !== $to) {
                $next[$from][] = [$to, self::str($row['san'])];
            }
        }
        $paths = [$entry['fromFen'] => $entry['path']];
        $queue = [$entry['fromFen']];
        for ($head = 0; $head < \count($queue); ++$head) {
            $from = $queue[$head];
            foreach ($next[$from] ?? [] as [$to, $san]) {
                if (!isset($paths[$to])) {
                    $paths[$to] = [...$paths[$from], $san];
                    $queue[] = $to;
                }
            }
        }

        return $paths;
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

    private static function str(mixed $value): string
    {
        return \is_string($value) ? $value : (\is_int($value) ? (string) $value : throw new \UnexpectedValueException('Trash row out of shape.'));
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
