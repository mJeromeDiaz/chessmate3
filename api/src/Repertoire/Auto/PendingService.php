<?php

declare(strict_types=1);

namespace App\Repertoire\Auto;

use App\Chess\Rules;
use App\Entity\Repertoire\Pending;
use App\Entity\Repertoire\Repertoire;
use App\Entity\User;
use App\Enum\Repertoire\PendingStatus;
use App\Repertoire\Exception\IllegalMoveException;
use App\Repertoire\Exception\LimitReachedException;
use App\Repertoire\Exception\PositionOccupiedException;
use App\Repertoire\Exception\RepeatedPositionException;
use App\Repertoire\Exception\RepertoireNotFoundException;
use App\Repertoire\Exception\StaleVersionException;
use App\Repertoire\Graph\Change;
use App\Repertoire\Graph\GraphEditor;
use App\Repertoire\Import\ImportedTree;
use App\Repertoire\Import\ImportPlanner;
use App\Repertoire\Import\Target;
use App\Repository\Repertoire\PendingRepository;
use App\Repository\Repertoire\RepertoireRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The pending positions of a repertoire built from the user's games (docs/REPERTOIRE.md, "En
 * suspens"). They never change the repertoire by themselves: answering one adds the user's move,
 * and the moves that lead there, through the import path (one change, undoable). Their status
 * follows the repertoire: one whose position has a prepared move is answered (added in the editor
 * too), one whose answer was undone is open again.
 */
final readonly class PendingService
{
    public function __construct(
        private PendingRepository $pendings,
        private RepertoireRepository $repertoires,
        private GraphEditor $editor,
        private ImportPlanner $planner,
        private Connection $connection,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * The repertoire's pending positions, their status brought in line with it.
     *
     * @return list<Pending>
     *
     * @throws RepertoireNotFoundException
     */
    public function list(User $user, Uuid $repertoireId): array
    {
        $repertoire = $this->repertoires->findOwned($repertoireId, $user) ?? throw new RepertoireNotFoundException();
        $pendings = $this->pendings->findByRepertoire($repertoire);
        $prepared = $this->preparedMoves($repertoire);
        $now = $this->clock->now();
        $changed = false;
        foreach ($pendings as $pending) {
            $move = $prepared[$pending->getFen()] ?? null;
            if (PendingStatus::Answered === $pending->getStatus() && null === $move) {
                $pending->reopen($now);
                $changed = true;
            } elseif (PendingStatus::Open === $pending->getStatus() && null !== $move) {
                $pending->answer($move, $now);
                $changed = true;
            }
        }
        if ($changed) {
            $this->entityManager->flush();
        }

        return $pendings;
    }

    /**
     * Answers a pending position with the user's move: the moves from the initial position and
     * that one are imported (those already there are kept). Checked before any write: the move is
     * legal, does not go back to a position of the line, and no position on the way already has
     * another prepared move.
     *
     * @throws RepertoireNotFoundException unknown repertoire or pending position
     * @throws PendingNotOpenException
     * @throws IllegalMoveException
     * @throws RepeatedPositionException
     * @throws PositionOccupiedException
     * @throws LimitReachedException
     * @throws StaleVersionException
     */
    public function answer(User $user, Uuid $repertoireId, Uuid $pendingId, string $uci, ?int $baseVersion): Change
    {
        [$repertoire, $pending] = $this->find($user, $repertoireId, $pendingId);
        $prepared = $this->preparedMoves($repertoire);
        if (isset($prepared[$pending->getFen()])) {
            throw new PositionOccupiedException('');
        }
        if (PendingStatus::Open !== $pending->getStatus()) {
            throw new PendingNotOpenException();
        }

        $tree = self::imported($pending, $uci);
        $plan = $this->planner->plan($tree, $repertoire->getColor(), Target::load($this->connection, $repertoire));
        if ([] !== $plan->conflicts || [] !== $plan->replaced) {
            throw new PositionOccupiedException('');
        }
        $change = $this->editor->import($user, $repertoire->getId(), fn (Repertoire $current) => $this->planner->plan($tree, $current->getColor(), Target::load($this->connection, $current)), $baseVersion);
        $pending->answer($uci, $this->clock->now());
        $this->entityManager->flush();

        return $change;
    }

    /**
     * @throws RepertoireNotFoundException
     * @throws PendingNotOpenException
     */
    public function dismiss(User $user, Uuid $repertoireId, Uuid $pendingId): Pending
    {
        [, $pending] = $this->find($user, $repertoireId, $pendingId);
        if (PendingStatus::Open !== $pending->getStatus()) {
            throw new PendingNotOpenException();
        }
        $pending->dismiss($this->clock->now());
        $this->entityManager->flush();

        return $pending;
    }

    /**
     * @throws RepertoireNotFoundException
     * @throws PendingNotOpenException
     */
    public function restore(User $user, Uuid $repertoireId, Uuid $pendingId): Pending
    {
        [, $pending] = $this->find($user, $repertoireId, $pendingId);
        if (PendingStatus::Dismissed !== $pending->getStatus()) {
            throw new PendingNotOpenException();
        }
        $pending->reopen($this->clock->now());
        $this->entityManager->flush();

        return $pending;
    }

    /**
     * The moves from the initial position to the pending position, then the user's answer.
     *
     * @throws IllegalMoveException
     * @throws RepeatedPositionException
     */
    public static function imported(Pending $pending, string $uci): ImportedTree
    {
        $rules = Rules::initial();
        $from = $rules->normalizedFen();
        $tree = new ImportedTree();
        $tree->positions[$from] = 'w';
        $tree->games = 1;
        $seen = [$from => true];
        foreach ($pending->getPath() as $san) {
            $move = $rules->playSan($san) ?? throw new IllegalMoveException();
            $to = $rules->normalizedFen();
            $tree->addEdge($from, $to, $rules->sideToMove(), Rules::uci($move), (string) $move->san, null, [], 1);
            $seen[$to] = true;
            $from = $to;
        }
        if ($from !== $pending->getFen()) {
            throw new IllegalMoveException();
        }
        $move = 1 === preg_match('/^[a-h][1-8][a-h][1-8][qrbn]?$/', $uci) ? $rules->playUci($uci) : null;
        if (null === $move) {
            throw new IllegalMoveException();
        }
        $to = $rules->normalizedFen();
        if (isset($seen[$to])) {
            throw new RepeatedPositionException();
        }
        $tree->addEdge($from, $to, $rules->sideToMove(), Rules::uci($move), (string) $move->san, null, [], 1);

        return $tree;
    }

    /**
     * @return array{Repertoire, Pending}
     *
     * @throws RepertoireNotFoundException
     */
    private function find(User $user, Uuid $repertoireId, Uuid $pendingId): array
    {
        $repertoire = $this->repertoires->findOwned($repertoireId, $user) ?? throw new RepertoireNotFoundException();
        $pending = $this->pendings->findInRepertoire($pendingId, $repertoire) ?? throw new RepertoireNotFoundException();

        return [$repertoire, $pending];
    }

    /**
     * The repertoire's prepared moves: normalized FEN => UCI.
     *
     * @return array<string, string>
     */
    private function preparedMoves(Repertoire $repertoire): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT p.fen, m.uci FROM repertoire_position p JOIN repertoire_move m ON m.from_position_id = p.id AND m.role = 'reference' WHERE p.repertoire_id = ?",
            [$repertoire->getId()->toBinary()],
        );
        $prepared = [];
        foreach ($rows as $row) {
            if (\is_string($row['fen']) && \is_string($row['uci'])) {
                $prepared[$row['fen']] = $row['uci'];
            }
        }

        return $prepared;
    }
}
