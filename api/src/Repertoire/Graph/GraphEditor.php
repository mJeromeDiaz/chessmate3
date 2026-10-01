<?php

declare(strict_types=1);

namespace App\Repertoire\Graph;

use App\Chess\Position\PositionKey;
use App\Chess\Rules;
use App\Entity\Repertoire\Move;
use App\Entity\Repertoire\Position;
use App\Entity\Repertoire\Repertoire;
use App\Entity\Repertoire\Revision;
use App\Entity\User;
use App\Enum\Repertoire\MoveRole;
use App\Enum\Repertoire\TrashReason;
use App\Repertoire\Exception\IllegalMoveException;
use App\Repertoire\Exception\InvalidAnnotationException;
use App\Repertoire\Exception\LimitReachedException;
use App\Repertoire\Exception\MoveNotFoundException;
use App\Repertoire\Exception\NothingToUndoException;
use App\Repertoire\Exception\PositionNotFoundException;
use App\Repertoire\Exception\PositionOccupiedException;
use App\Repertoire\Exception\RepeatedPositionException;
use App\Repertoire\Exception\RepertoireNotFoundException;
use App\Repertoire\Exception\RestoreImpossibleException;
use App\Repertoire\Exception\StaleVersionException;
use App\Repertoire\Exception\TrashNotFoundException;
use App\Repertoire\Import\ImportPlan;
use App\Repertoire\Import\Target;
use App\Repertoire\Limits;
use App\Repertoire\Transaction;
use App\Repository\Repertoire\MoveRepository;
use App\Repository\Repertoire\PositionRepository;
use App\Repository\Repertoire\RepertoireRepository;
use App\Repository\Repertoire\RevisionRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Every change of a repertoire's graph (docs/REPERTOIRE.md), each in one transaction with the
 * repertoire row locked first. Where the user is to move, a position holds exactly one prepared
 * move (the database guarantees it); opponent moves are replies, any number.
 *
 * - addMove: a legal move from a position of the repertoire. The position it reaches is found by
 *   its normalized FEN (a transposition joins the existing position) or created. A second move of
 *   the user in a position is refused ({@see PositionOccupiedException}): replace it instead. A
 *   move leading back to a position its line comes from is refused (the graph stays acyclic);
 * - replace: another move for the user in a position; the former one, with everything only
 *   reachable through it, goes to the trash;
 * - promote (first among the replies), annotate (plain-text comment, NAGs);
 * - delete: the move and everything only reachable through it, to the trash;
 * - restore: a suite of the trash back into the repertoire, with its ids (segments and cards come
 *   back with their progress), the user choosing where it conflicts ({@see RestorePlanner});
 *   discard: a suite out of the trash for good;
 * - import: a whole import in one change (App\Repertoire\Import);
 * - undo: reverts the latest change (journal of {@see Limits::$undoDepth} changes, rows restored
 *   with their ids, trash entries taken back).
 *
 * After each change the derived data (canonical tree, depths, segments) is recomputed from the
 * whole graph ({@see GraphIndexer}) and written back where it differs. A client sending the
 * version it is based on gets a {@see StaleVersionException} when another change came first.
 *
 * @phpstan-import-type TrashEntry from TrashBin
 * @phpstan-import-type Rows from RowStore
 */
final class GraphEditor
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Connection $connection,
        private readonly RepertoireRepository $repertoires,
        private readonly PositionRepository $positions,
        private readonly MoveRepository $moves,
        private readonly RevisionRepository $revisions,
        private readonly GraphLoader $loader,
        private readonly GraphIndexer $indexer,
        private readonly IndexWriter $indexWriter,
        private readonly SegmentReconciler $segmentReconciler,
        private readonly RowStore $rows,
        private readonly TrashBin $trash,
        private readonly RestorePlanner $restorePlanner,
        private readonly Limits $limits,
        private readonly ClockInterface $clock,
        private readonly Transaction $transaction,
    ) {
    }

    /**
     * @throws RepertoireNotFoundException
     * @throws PositionNotFoundException
     * @throws PositionOccupiedException
     * @throws IllegalMoveException
     * @throws RepeatedPositionException
     * @throws LimitReachedException
     * @throws StaleVersionException
     */
    public function addMove(User $user, Uuid $repertoireId, Uuid $fromPositionId, string $uci, ?int $baseVersion = null): Change
    {
        return $this->mutate($user, $repertoireId, $baseVersion, function (Repertoire $repertoire, Change $change, \DateTimeImmutable $now) use ($fromPositionId, $uci): ?array {
            $from = $this->positions->findInRepertoire($fromPositionId, $repertoire) ?? throw new PositionNotFoundException();
            $existing = $this->moves->findFrom($from, $uci);
            if (null !== $existing) {
                $change->moveId = $existing->getId()->toRfc4122();

                return null;
            }
            $siblings = $this->moves->findSiblings($from);
            if ($from->isUserTurn() && [] !== $siblings) {
                throw new PositionOccupiedException($siblings[0]->getId()->toRfc4122());
            }
            $move = $this->playFrom($repertoire, $from, $uci, \count($siblings), $change, $now, $this->loader->load($repertoire), $repertoire->getPositionCount());

            return ['add', ['op' => 'delete', 'moveId' => $move->getId()->toRfc4122()]];
        });
    }

    /**
     * Another prepared move in a user's position: the former one, and everything only reachable
     * through it, go to the trash.
     *
     * @throws RepertoireNotFoundException
     * @throws MoveNotFoundException
     * @throws IllegalMoveException       not a user's move, or an illegal one
     * @throws RepeatedPositionException
     * @throws LimitReachedException
     * @throws StaleVersionException
     */
    public function replace(User $user, Uuid $repertoireId, Uuid $moveId, string $uci, ?int $baseVersion = null): Change
    {
        return $this->mutate($user, $repertoireId, $baseVersion, function (Repertoire $repertoire, Change $change, \DateTimeImmutable $now) use ($moveId, $uci): ?array {
            $old = $this->moves->findInRepertoire($moveId, $repertoire) ?? throw new MoveNotFoundException();
            if (MoveRole::Reference !== $old->getRole()) {
                throw new IllegalMoveException('Only a prepared move of the user is replaced.');
            }
            if ($old->getUci() === $uci) {
                $change->moveId = $old->getId()->toRfc4122();

                return null;
            }
            $from = $old->getFrom();
            $rules = Rules::fromFen($from->getFen());
            $played = $rules->playUci($uci) ?? throw new IllegalMoveException();
            $graph = $this->loader->load($repertoire);
            // A position the new move reaches stays, even if only the former move reached it.
            $reached = $this->positions->findByKey($repertoire, PositionKey::of($rules->normalizedFen()));
            if (null !== $reached) {
                $graph->addMove(new GraphMove('pending', $from->getId()->toRfc4122(), $reached->getId()->toRfc4122(), MoveRole::Reference, 0, false, null, (string) $played->san));
            }
            $trashId = $this->toTrash($repertoire, $graph, [$old->getId()->toRfc4122()], TrashReason::Replaced, $change, $now)[0];
            if (null !== $reached) {
                $graph->removeMove('pending');
            }
            $move = $this->playFrom($repertoire, $from, $uci, 0, $change, $now, $graph, \count($graph->positions()));
            $change->trashId = $trashId;

            return ['replace', ['op' => 'replaceback', 'moveId' => $move->getId()->toRfc4122(), 'trashId' => $trashId]];
        });
    }

    /**
     * Puts a move first among the moves from its position (the main line).
     *
     * @throws RepertoireNotFoundException
     * @throws MoveNotFoundException
     * @throws StaleVersionException
     */
    public function promote(User $user, Uuid $repertoireId, Uuid $moveId, ?int $baseVersion = null): Change
    {
        return $this->mutate($user, $repertoireId, $baseVersion, function (Repertoire $repertoire, Change $change) use ($moveId): ?array {
            $move = $this->moves->findInRepertoire($moveId, $repertoire) ?? throw new MoveNotFoundException();
            $siblings = $this->moves->findSiblings($move->getFrom());
            if ($siblings[0] === $move) {
                return null;
            }
            $orders = [];
            $next = 1;
            foreach ($siblings as $sibling) {
                $orders[$sibling->getId()->toRfc4122()] = $sibling === $move ? 0 : $next++;
            }
            $inverse = ['op' => 'orders', 'orders' => array_combine(array_keys($orders), array_map(static fn (Move $m): int => $m->getSortOrder(), $siblings))];
            foreach ($siblings as $sibling) {
                $sibling->setSortOrder($orders[$sibling->getId()->toRfc4122()]);
            }
            $this->entityManager->flush();
            $change->touchMoves(array_keys($orders));

            return ['promote', $inverse];
        });
    }

    /**
     * @param list<int> $nags
     *
     * @throws RepertoireNotFoundException
     * @throws MoveNotFoundException
     * @throws InvalidAnnotationException
     * @throws StaleVersionException
     */
    public function annotate(User $user, Uuid $repertoireId, Uuid $moveId, ?string $comment, array $nags, ?int $baseVersion = null): Change
    {
        [$comment, $nags] = Annotation::normalize($comment, $nags);

        return $this->mutate($user, $repertoireId, $baseVersion, function (Repertoire $repertoire, Change $change) use ($moveId, $comment, $nags): ?array {
            $move = $this->moves->findInRepertoire($moveId, $repertoire) ?? throw new MoveNotFoundException();
            if ($move->getComment() === $comment && $move->getNags() === $nags) {
                return null;
            }
            $inverse = ['op' => 'annotate', 'moveId' => $move->getId()->toRfc4122(), 'comment' => $move->getComment(), 'nags' => $move->getNags()];
            $move->annotate($comment, $nags);
            $this->entityManager->flush();
            $change->touchMoves([$inverse['moveId']]);

            return ['annotate', $inverse];
        });
    }

    /**
     * Deletes a move and everything only reachable through it: to the trash.
     *
     * @throws RepertoireNotFoundException
     * @throws MoveNotFoundException
     * @throws StaleVersionException
     */
    public function delete(User $user, Uuid $repertoireId, Uuid $moveId, ?int $baseVersion = null): Change
    {
        return $this->mutate($user, $repertoireId, $baseVersion, function (Repertoire $repertoire, Change $change, \DateTimeImmutable $now) use ($moveId): array {
            $move = $this->moves->findInRepertoire($moveId, $repertoire) ?? throw new MoveNotFoundException();
            $trashId = $this->toTrash($repertoire, $this->loader->load($repertoire), [$move->getId()->toRfc4122()], TrashReason::Deleted, $change, $now)[0];
            $change->trashId = $trashId;

            return ['delete', ['op' => 'untrash', 'trashId' => $trashId]];
        });
    }

    /**
     * What restoring a suite of the trash would do, with these choices (no change).
     *
     * @param array<string, 'restored'|'current'> $choices
     *
     * @return array{TrashEntry, RestorePlan}
     *
     * @throws RepertoireNotFoundException
     * @throws TrashNotFoundException
     * @throws RestoreImpossibleException
     */
    public function previewRestore(User $user, Uuid $repertoireId, string $trashId, array $choices = []): array
    {
        $repertoire = $this->repertoires->findOwned($repertoireId, $user) ?? throw new RepertoireNotFoundException();
        $entry = $this->trash->find($repertoire, $trashId) ?? throw new TrashNotFoundException();

        return [$entry, $this->planRestore($repertoire, $entry, $choices)];
    }

    /**
     * Brings a suite of the trash back, with its ids. Where it conflicts, the choices say which
     * move stays; the moves it replaces go to the trash with their own suites.
     *
     * @param array<string, 'restored'|'current'> $choices
     *
     * @throws RepertoireNotFoundException
     * @throws TrashNotFoundException
     * @throws RestoreImpossibleException
     * @throws LimitReachedException
     * @throws StaleVersionException
     */
    public function restore(User $user, Uuid $repertoireId, string $trashId, array $choices = [], ?int $baseVersion = null): Change
    {
        return $this->mutate($user, $repertoireId, $baseVersion, function (Repertoire $repertoire, Change $change, \DateTimeImmutable $now) use ($trashId, $choices): array {
            $entry = $this->trash->find($repertoire, $trashId) ?? throw new TrashNotFoundException();
            $plan = $this->planRestore($repertoire, $entry, $choices);

            // The merged graph first: limits, and what the replaced moves cut off.
            $graph = $this->loader->load($repertoire);
            foreach ($plan->positions as $row) {
                $graph->addPosition(new GraphPosition(self::string($row['id']), self::string($row['turn'])));
            }
            foreach ($plan->moves as $row) {
                $graph->addMove(new GraphMove(self::string($row['id']), self::string($row['from_position_id']), self::string($row['to_position_id']), MoveRole::from(self::string($row['role'])), 0, false, null, self::string($row['san'])));
            }
            $displaced = $this->toTrash($repertoire, $graph, $plan->replaced, TrashReason::Replaced, $change, $now, commit: false);
            $this->checkLimits($graph);

            $this->commitTrash($repertoire, $displaced, $change);
            $inserted = $this->rows->insert($repertoire, ['positions' => $plan->positions, 'moves' => $plan->moves], exact: false);
            $this->trash->remove($entry['id']);
            $change->touchPositions($inserted['positions']);
            $change->touchMoves($inserted['moves']);

            return ['restore', [
                'op' => 'unrestore',
                'moves' => $inserted['moves'],
                'positions' => $inserted['positions'],
                'entry' => $entry,
                'displaced' => array_map(static fn (array $pending): string => $pending['id'], $displaced),
            ]];
        });
    }

    /**
     * Removes a suite from the trash for good (not undoable).
     *
     * @throws RepertoireNotFoundException
     * @throws TrashNotFoundException
     */
    public function discard(User $user, Uuid $repertoireId, string $trashId): void
    {
        $this->transaction->run(function () use ($user, $repertoireId, $trashId): void {
            $repertoire = $this->repertoires->lockOwned($repertoireId, $user) ?? throw new RepertoireNotFoundException();
            $entry = $this->trash->find($repertoire, $trashId) ?? throw new TrashNotFoundException();
            $this->trash->remove($entry['id']);
        });
    }

    /**
     * Applies an import in one change (one entry of the undo journal): $plan is called once the
     * repertoire is locked and returns what to write (App\Repertoire\Import\ImportPlanner). The
     * prepared moves the file replaces go to the trash with their suites. Everything is checked
     * before the first write: the position limit, and the depth limit on the merged graph. Rows
     * are written in SQL, in batches.
     *
     * @param callable(Repertoire): ImportPlan $plan
     *
     * @throws RepertoireNotFoundException
     * @throws LimitReachedException
     * @throws StaleVersionException
     */
    public function import(User $user, Uuid $repertoireId, callable $plan, ?int $baseVersion = null): Change
    {
        return $this->mutate($user, $repertoireId, $baseVersion, function (Repertoire $repertoire, Change $change, \DateTimeImmutable $now) use ($plan): ?array {
            $import = $plan($repertoire);
            if ($import->positionsAfter > $this->limits->maxPositions) {
                throw new LimitReachedException('positions', $this->limits->maxPositions);
            }
            if ([] === $import->moves && [] === $import->fills && [] === $import->replaced) {
                return null;
            }

            // Ids now (UUID v7: creation order is file order, which the canonical choice follows).
            $graph = $this->loader->load($repertoire);
            $existing = $this->rows->positionIdsByFen($repertoire);
            $positionIds = [];
            foreach ($import->positions as $fen => $turn) {
                $positionIds[$fen] = Uuid::v7()->toRfc4122();
                $graph->addPosition(new GraphPosition($positionIds[$fen], $turn));
            }
            $moveIds = [];
            foreach ($import->moves as $i => $move) {
                $moveIds[$i] = Uuid::v7()->toRfc4122();
                $graph->addMove(new GraphMove($moveIds[$i], $positionIds[$move['from']] ?? $existing[$move['from']], $positionIds[$move['to']] ?? $existing[$move['to']], MoveRole::from($move['role']), $move['sortOrder'], false, null, $move['san']));
            }
            $trashed = $this->toTrash($repertoire, $graph, $import->replaced, TrashReason::Imported, $change, $now, commit: false);
            $this->checkLimits($graph);

            // What undoing restores, read before writing.
            $before = [];
            foreach ($this->rows->rows('SELECT id, comment, nags FROM repertoire_move WHERE id IN (?)', array_keys($import->fills)) as $row) {
                $before[self::string($row['id'])] = $row;
            }
            $annotations = [];
            foreach (array_keys($import->fills) as $id) {
                $annotations[$id] = ['comment' => $before[$id]['comment'] ?? null, 'nags' => $before[$id]['nags'] ?? '[]'];
            }

            $this->commitTrash($repertoire, $trashed, $change);
            $positionRows = [];
            foreach ($import->positions as $fen => $turn) {
                $positionRows[] = [Uuid::fromString($positionIds[$fen])->toBinary(), $repertoire->getId()->toBinary(), $repertoire->getUser()->getId()->toBinary(), $fen, PositionKey::of($fen)->hash, $turn, 0, $now->format('Y-m-d H:i:s')];
            }
            $this->rows->insertRows('repertoire_position', ['id', 'repertoire_id', 'user_id', 'fen', 'fen_hash', 'turn', 'depth', 'created_at'], $positionRows);
            $moveRows = [];
            foreach ($import->moves as $i => $move) {
                $moveRows[] = [
                    Uuid::fromString($moveIds[$i])->toBinary(),
                    $repertoire->getId()->toBinary(),
                    Uuid::fromString($positionIds[$move['from']] ?? $existing[$move['from']])->toBinary(),
                    Uuid::fromString($positionIds[$move['to']] ?? $existing[$move['to']])->toBinary(),
                    $move['uci'], $move['san'], $move['role'], $move['sortOrder'], $move['comment'],
                    json_encode($move['nags'], \JSON_THROW_ON_ERROR), 0, $now->format('Y-m-d H:i:s'),
                ];
            }
            $this->rows->insertRows('repertoire_move', ['id', 'repertoire_id', 'from_position_id', 'to_position_id', 'uci', 'san', 'role', 'sort_order', 'comment', 'nags', 'canonical', 'created_at'], $moveRows);
            foreach ($import->fills as $id => $fill) {
                $old = $before[$id] ?? [];
                $nags = json_decode(\is_string($old['nags'] ?? null) ? $old['nags'] : '[]', true);
                $this->rows->setAnnotation($id, $fill['comment'] ?? (\is_string($old['comment'] ?? null) ? $old['comment'] : null), $fill['nags'] ?? (\is_array($nags) ? array_values(array_filter($nags, 'is_int')) : []));
            }
            $change->touchPositions(array_values($positionIds));
            $change->touchMoves([...array_values($moveIds), ...array_keys($import->fills)]);

            return ['import', [
                'op' => 'unimport',
                'moves' => array_values($moveIds),
                'positions' => array_values($positionIds),
                'annotations' => $annotations,
                'trash' => array_map(static fn (array $pending): string => $pending['id'], $trashed),
            ]];
        });
    }

    /**
     * Reverts the latest change still in the journal.
     *
     * @throws RepertoireNotFoundException
     * @throws NothingToUndoException
     * @throws StaleVersionException
     */
    public function undo(User $user, Uuid $repertoireId, ?int $baseVersion = null): Change
    {
        return $this->mutate($user, $repertoireId, $baseVersion, function (Repertoire $repertoire, Change $change, \DateTimeImmutable $now): array {
            $revision = $this->revisions->findLatest($repertoire) ?? throw new NothingToUndoException();
            $this->apply($repertoire, $revision->getInverse(), $change);
            $this->entityManager->remove($revision);
            $this->entityManager->flush();

            return ['undo', null];
        });
    }

    /**
     * @param callable(Repertoire, Change, \DateTimeImmutable): (array{string, array<string, mixed>|null}|null) $operation
     *                                                                                                                    returns the operation name and its inverse (null: not journaled), or null when nothing changed
     */
    private function mutate(User $user, Uuid $repertoireId, ?int $baseVersion, callable $operation): Change
    {
        return $this->transaction->run(function () use ($user, $repertoireId, $baseVersion, $operation): Change {
            $repertoire = $this->repertoires->lockOwned($repertoireId, $user) ?? throw new RepertoireNotFoundException();
            if (null !== $baseVersion && $baseVersion !== $repertoire->getVersion()) {
                throw new StaleVersionException($repertoire->getVersion());
            }
            $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
            $change = new Change($repertoire->getVersion(), 'none');
            $done = $operation($repertoire, $change, $now);
            if (null === $done) {
                return $change;
            }

            $graph = $this->loader->load($repertoire);
            $index = $this->indexer->index($graph);
            $derived = $this->indexWriter->write($graph, $index);
            $change->touchMoves($derived['moves']);
            $change->touchPositions($derived['positions']);
            $change->touchMoves($this->segmentReconciler->reconcile($repertoire, $graph, $index, $now));

            $repertoire->setPositionCount(\count($graph->positions()));
            $change->version = $repertoire->touch($now);
            $change->operation = $done[0];
            if (null !== $done[1]) {
                $this->entityManager->persist(new Revision($repertoire, $change->version, $done[0], $done[1], $now));
            }
            $this->entityManager->flush();
            $this->revisions->trim($repertoire, $this->limits->undoDepth);

            return $change;
        });
    }

    /**
     * Plays a legal move from a position (checked: cycle, limits) and stores it with the position
     * it reaches when new.
     */
    private function playFrom(Repertoire $repertoire, Position $from, string $uci, int $sortOrder, Change $change, \DateTimeImmutable $now, Graph $graph, int $positionCount): Move
    {
        $rules = Rules::fromFen($from->getFen());
        $played = $rules->playUci($uci) ?? throw new IllegalMoveException();
        $key = PositionKey::of($rules->normalizedFen());

        $to = $this->positions->findByKey($repertoire, $key);
        if (null !== $to) {
            if ($graph->leadsTo($to->getId()->toRfc4122(), $from->getId()->toRfc4122())) {
                throw new RepeatedPositionException();
            }
            $change->transposition = true;
        } else {
            if ($from->getDepth() + 1 > $this->limits->maxDepth) {
                throw new LimitReachedException('depth', $this->limits->maxDepth);
            }
            if ($positionCount >= $this->limits->maxPositions) {
                throw new LimitReachedException('positions', $this->limits->maxPositions);
            }
            $to = new Position($repertoire, $key, $from->getDepth() + 1, $now);
            $this->entityManager->persist($to);
            $change->touchPositions([$to->getId()->toRfc4122()]);
        }

        $role = $from->isUserTurn() ? MoveRole::Reference : MoveRole::Reply;
        $move = new Move($from, $to, Rules::uci($played), (string) $played->san, $role, $sortOrder, !$change->transposition, $now);
        $this->entityManager->persist($move);
        $this->entityManager->flush();
        $change->moveId = $move->getId()->toRfc4122();
        $change->touchMoves([$change->moveId]);

        return $move;
    }

    /**
     * Cuts moves (with what only they reach) off the graph and, unless $commit is false (the
     * caller commits later with {@see self::commitTrash()}), moves them to the trash: one entry
     * per move.
     *
     * @param list<string> $moveIds
     *
     * @return ($commit is true ? list<string> : list<array{id: string, entry: TrashEntry, moves: list<string>, positions: list<string>}>)
     */
    private function toTrash(Repertoire $repertoire, Graph $graph, array $moveIds, TrashReason $reason, Change $change, \DateTimeImmutable $now, bool $commit = true): array
    {
        if ([] === $moveIds) {
            return [];
        }
        $fens = array_flip($this->rows->positionIdsByFen($repertoire));
        $pending = [];
        $paths = [];
        foreach ($moveIds as $id) {
            $move = $graph->move($id) ?? throw new \LogicException('No move '.$id);
            $paths[$id] = [$move, $graph->canonicalPath($move->from)];
        }
        foreach ($graph->cut($moveIds) as $id => $parts) {
            [$move, $path] = $paths[$id];
            $rows = $this->rows->snapshot($parts['positions'], $parts['moves']);
            $inside = array_flip($parts['positions']);
            $external = [];
            foreach ($rows['moves'] as $row) {
                foreach (['from_position_id', 'to_position_id'] as $column) {
                    $positionId = self::string($row[$column]);
                    if (!isset($inside[$positionId]) && isset($fens[$positionId])) {
                        $external[$positionId] = $fens[$positionId];
                    }
                }
            }
            $rows['external'] = $external;
            $uci = self::string(array_values(array_filter($rows['moves'], static fn (array $row): bool => $row['id'] === $id))[0]['uci'] ?? '');
            $entry = [
                'id' => Uuid::v7()->toRfc4122(),
                'reason' => $reason->value,
                'fromFen' => $fens[$move->from] ?? throw new \LogicException('No FEN for '.$move->from),
                'uci' => $uci,
                'san' => $move->san,
                'path' => $path,
                'positionCount' => \count($rows['positions']),
                'moveCount' => \count($rows['moves']),
                'rows' => $rows,
                'createdAt' => $now->format('Y-m-d H:i:s'),
            ];
            $pending[] = ['id' => $entry['id'], 'entry' => $entry, 'moves' => $parts['moves'], 'positions' => $parts['positions']];
        }
        if (!$commit) {
            return $pending;
        }
        $this->commitTrash($repertoire, $pending, $change);

        return array_map(static fn (array $item): string => $item['id'], $pending);
    }

    /**
     * @param list<array{id: string, entry: TrashEntry, moves: list<string>, positions: list<string>}> $pending
     */
    private function commitTrash(Repertoire $repertoire, array $pending, Change $change): void
    {
        foreach ($pending as $item) {
            $this->trash->insert($repertoire, $item['entry']);
            $this->rows->delete($item['moves'], $item['positions']);
            $change->delete($item['moves'], $item['positions']);
        }
    }

    /**
     * @param TrashEntry                          $entry
     * @param array<string, 'restored'|'current'> $choices
     */
    private function planRestore(Repertoire $repertoire, array $entry, array $choices): RestorePlan
    {
        try {
            return $this->restorePlanner->plan($entry, Target::load($this->connection, $repertoire), $repertoire->getColor(), $choices);
        } catch (\DomainException) {
            throw new RestoreImpossibleException();
        }
    }

    /**
     * @throws LimitReachedException
     */
    private function checkLimits(Graph $graph): void
    {
        if (\count($graph->positions()) > $this->limits->maxPositions) {
            throw new LimitReachedException('positions', $this->limits->maxPositions);
        }
        $depths = $this->indexer->index($graph)->depth;
        if ([] !== $depths && max($depths) > $this->limits->maxDepth) {
            throw new LimitReachedException('depth', $this->limits->maxDepth);
        }
    }

    /**
     * Applies the inverse of a journaled change.
     *
     * @param array<string, mixed> $inverse
     *
     * @throws NothingToUndoException the trash changed since (entry restored or discarded)
     */
    private function apply(Repertoire $repertoire, array $inverse, Change $change): void
    {
        switch ($inverse['op'] ?? null) {
            case 'delete':
                // Undoing an addition: gone for good, not to the trash.
                $graph = $this->loader->load($repertoire);
                foreach ($graph->cut([self::string($inverse['moveId'] ?? null)]) as $parts) {
                    $this->rows->delete($parts['moves'], $parts['positions']);
                    $change->delete($parts['moves'], $parts['positions']);
                }
                break;
            case 'untrash':
                $this->untrash($repertoire, self::string($inverse['trashId'] ?? null), $change);
                break;
            case 'replaceback':
                // The new move first, alone: a position it reached may belong to the former suite
                // (kept by the replacement), so what it cut off is known once that suite is back.
                $moveId = self::string($inverse['moveId'] ?? null);
                $this->rows->delete([$moveId], []);
                $change->delete([$moveId], []);
                $this->untrash($repertoire, self::string($inverse['trashId'] ?? null), $change);
                $graph = $this->loader->load($repertoire);
                $reachable = $graph->reachable();
                $lost = array_values(array_filter(array_map('strval', array_keys($graph->positions())), static fn (string $id): bool => !isset($reachable[$id])));
                $inside = array_flip($lost);
                $moves = array_values(array_map(static fn (GraphMove $m): string => $m->id, array_filter($graph->moves(), static fn (GraphMove $m): bool => isset($inside[$m->from]))));
                $this->rows->delete($moves, $lost);
                $change->delete($moves, $lost);
                break;
            case 'unrestore':
                $moves = array_map(self::string(...), self::list($inverse['moves'] ?? []));
                $positions = array_map(self::string(...), self::list($inverse['positions'] ?? []));
                $this->rows->delete($moves, $positions);
                $change->delete($moves, $positions);
                foreach (self::list($inverse['displaced'] ?? []) as $id) {
                    $this->untrash($repertoire, self::string($id), $change);
                }
                /** @var TrashEntry $entry */
                $entry = self::map($inverse['entry'] ?? null);
                $this->trash->insert($repertoire, $entry);
                break;
            case 'unimport':
                $moves = array_map(self::string(...), self::list($inverse['moves'] ?? []));
                $positions = array_map(self::string(...), self::list($inverse['positions'] ?? []));
                $this->rows->delete($moves, $positions);
                $change->delete($moves, $positions);
                foreach (self::list($inverse['trash'] ?? []) as $id) {
                    $this->untrash($repertoire, self::string($id), $change);
                }
                foreach (self::map($inverse['annotations'] ?? []) as $id => $annotation) {
                    $annotation = self::map($annotation);
                    $nags = json_decode(\is_string($annotation['nags'] ?? null) ? $annotation['nags'] : '[]', true);
                    $this->rows->setAnnotation($id, \is_string($annotation['comment'] ?? null) ? $annotation['comment'] : null, \is_array($nags) ? array_values(array_filter($nags, 'is_int')) : []);
                    $change->touchMoves([$id]);
                }
                break;
            case 'orders':
                $orders = [];
                foreach (self::map($inverse['orders'] ?? []) as $id => $order) {
                    $orders[$id] = is_numeric($order) ? (int) $order : 0;
                }
                $this->rows->setOrders($orders);
                $change->touchMoves(array_keys($orders));
                break;
            case 'annotate':
                $nags = array_values(array_filter(self::list($inverse['nags'] ?? []), 'is_int'));
                $id = self::string($inverse['moveId'] ?? null);
                $this->rows->setAnnotation($id, \is_string($inverse['comment'] ?? null) ? $inverse['comment'] : null, $nags);
                $change->touchMoves([$id]);
                break;
            default:
                throw new \LogicException('Unknown journal entry.');
        }
    }

    /**
     * Puts a trash entry's rows back exactly (ids, canonical moves) and removes the entry.
     *
     * @throws NothingToUndoException the entry is no longer in the trash
     */
    private function untrash(Repertoire $repertoire, string $trashId, Change $change): void
    {
        $entry = $this->trash->find($repertoire, $trashId) ?? throw new NothingToUndoException('The trash changed since: this change can no longer be undone.');
        $inserted = $this->rows->insert($repertoire, $entry['rows']);
        $this->trash->remove($trashId);
        $change->touchPositions($inserted['positions']);
        $change->touchMoves($inserted['moves']);
    }

    private static function string(mixed $value): string
    {
        return \is_string($value) ? $value : throw new \LogicException('Journal out of sync.');
    }

    /**
     * @return array<string, mixed>
     */
    private static function map(mixed $value): array
    {
        if (!\is_array($value)) {
            throw new \LogicException('Journal out of sync.');
        }

        return array_combine(array_map('strval', array_keys($value)), array_values($value));
    }

    /**
     * @return list<mixed>
     */
    private static function list(mixed $value): array
    {
        return \is_array($value) ? array_values($value) : throw new \LogicException('Journal out of sync.');
    }
}
