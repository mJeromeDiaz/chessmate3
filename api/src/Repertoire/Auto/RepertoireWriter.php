<?php

declare(strict_types=1);

namespace App\Repertoire\Auto;

use App\Chess\Position\PositionKey;
use App\Chess\Rules;
use App\Entity\Repertoire\AutoBuild;
use App\Entity\Repertoire\Pending;
use App\Entity\Repertoire\Repertoire;
use App\Enum\Repertoire\PendingReason;
use App\Entity\User;
use App\Repertoire\Exception\LimitReachedException;
use App\Repertoire\Import\ImportApplier;
use App\Repertoire\Import\ImportedTree;
use App\Repertoire\Limits;
use App\Repertoire\Transaction;
use App\Repository\Repertoire\RepertoireRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Creates the repertoires of a build (docs/REPERTOIRE.md, "Repertoire from my games"): one new
 * repertoire per opening family kept, through the import path (one change, undoable), with the
 * statistics of its moves (repertoire_game_stat), its origin and its pending positions
 * (repertoire_pending: never in the repertoire until the user answers them). All of them or none:
 * one transaction, the repertoire limit checked before the first write.
 */
final readonly class RepertoireWriter
{
    public const NAME_SUFFIX = ' (auto)';
    private const STAT_BATCH = 200;

    public function __construct(
        private ImportApplier $applier,
        private RepertoireRepository $repertoires,
        private Connection $connection,
        private EntityManagerInterface $entityManager,
        private Transaction $transaction,
        private Limits $limits,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param array<string, Tree> $trees family key => its tree (with lines)
     * @param array<string, string> $families family key => family name
     *
     * @return list<string> the repertoires created
     *
     * @throws LimitReachedException
     */
    public function write(User $user, AutoBuild $build, array $trees, array $families): array
    {
        $trees = array_filter($trees, static fn (Tree $tree): bool => [] !== $tree->lines);
        if ($this->repertoires->countByUser($user) + \count($trees) > $this->limits->maxRepertoires) {
            throw new LimitReachedException('repertoires', $this->limits->maxRepertoires);
        }
        $now = $this->clock->now();
        $taken = array_map(static fn (Repertoire $repertoire): string => $repertoire->getName(), $this->repertoires->findByUser($user));
        $evaluations = $build->getEvaluations();

        return $this->transaction->run(function () use ($user, $build, $trees, $families, $now, $taken, $evaluations): array {
            $ids = [];
            foreach ($trees as $key => $tree) {
                $name = self::name($families[$key] ?? (string) $key, $taken, $now->setTimezone($user->getDateTimeZone()));
                $taken[] = $name;
                $id = $this->applier->apply($user, self::imported($tree), ['name' => $name, 'color' => $tree->color]);
                // Through the entity, not raw SQL: the repertoire just created is in the identity map.
                $repertoire = $this->repertoires->find($id);
                $repertoire?->setOrigin(['source' => 'lichess_games', 'username' => $build->getUsername(), 'games' => $tree->root['games'], 'perfs' => $build->getPerfs(), 'builtAt' => $now->format(\DATE_ATOM)]);
                if (null !== $repertoire) {
                    $this->pending($repertoire, $tree, $now);
                }
                $this->entityManager->flush();
                $this->stats($id, $tree, $evaluations, $now);
                $ids[] = $id->toRfc4122();
            }

            return $ids;
        });
    }

    /**
     * "Italian Game (auto)", dated when the user has a repertoire of that name already.
     *
     * @param list<string> $taken
     */
    public static function name(string $family, array $taken, \DateTimeImmutable $localNow): string
    {
        $date = ' '.$localNow->format('d/m/Y');
        $room = Repertoire::NAME_MAX_LENGTH - mb_strlen(self::NAME_SUFFIX.$date);
        $name = mb_substr($family, 0, $room).self::NAME_SUFFIX;

        return \in_array($name, $taken, true) ? $name.$date : $name;
    }

    /**
     * The tree as an import (the import path validates and writes it): its moves in depth-first
     * order, the most played first (the main line).
     */
    public static function imported(Tree $tree): ImportedTree
    {
        $initial = Rules::initial()->normalizedFen();
        $imported = new ImportedTree();
        $imported->positions[$initial] = 'w';
        $imported->games = $tree->root['games'];
        foreach ($tree->nodes as $node) {
            $from = '' === $node['parent'] ? $initial : $tree->nodes[$node['parent']]['fen'];
            $imported->addEdge($from, $node['fen'], explode(' ', $node['fen'])[1] ?? 'w', $node['uci'], $node['san'], null, [], 1);
        }

        return $imported;
    }

    /**
     * The tree's pending positions, but those the repertoire answers already (a transposition
     * into a position where the user has their move).
     */
    private function pending(Repertoire $repertoire, Tree $tree, \DateTimeImmutable $now): void
    {
        $initial = Rules::initial()->normalizedFen();
        $answered = [];
        foreach ($tree->nodes as $node) {
            if ($node['user']) {
                $answered['' === $node['parent'] ? $initial : $tree->nodes[$node['parent']]['fen']] = true;
            }
        }
        foreach ($tree->pending as $pending) {
            if (isset($answered[$pending['fen']])) {
                continue;
            }
            $this->entityManager->persist(new Pending($repertoire, $pending['fen'], $pending['sans'], PendingReason::from($pending['reason']), $pending['games'], $pending['share'], $pending['score'], $now));
        }
    }

    /**
     * One row per move (a transposition's moves merged), the cloud evaluation on the line ends.
     *
     * @param array<string, array{cp: int|null, mate: int|null}|null> $evaluations
     */
    private function stats(Uuid $repertoireId, Tree $tree, array $evaluations, \DateTimeImmutable $now): void
    {
        $initial = Rules::initial()->normalizedFen();
        $leaves = array_flip(array_column($tree->lines, 'path'));
        /** @var array<string, array{fen: string, uci: string, games: int, wins: int, draws: int, losses: int, analysed: int, errored: int, thinkSum: int, thinkCount: int, moveAnalysed: int, moveErrors: int, best: string|null, cp: int|null, mate: int|null}> $rows */
        $rows = [];
        foreach ($tree->nodes as $path => $node) {
            $from = '' === $node['parent'] ? $initial : $tree->nodes[$node['parent']]['fen'];
            $key = $from.' '.$node['uci'];
            $evaluation = isset($leaves[$path]) ? ($evaluations[$node['fen']] ?? null) : null;
            $row = $rows[$key] ?? ['fen' => $from, 'uci' => $node['uci'], 'games' => 0, 'wins' => 0, 'draws' => 0, 'losses' => 0, 'analysed' => 0, 'errored' => 0, 'thinkSum' => 0, 'thinkCount' => 0, 'moveAnalysed' => 0, 'moveErrors' => 0, 'best' => null, 'cp' => null, 'mate' => null];
            foreach (['games', 'wins', 'draws', 'losses', 'analysed', 'errored', 'moveAnalysed', 'moveErrors'] as $field) {
                $row[$field] += $node[$field];
            }
            if (null !== $node['think']) {
                $row['thinkSum'] += $node['think'] * $node['games'];
                $row['thinkCount'] += $node['games'];
            }
            $row['best'] ??= $node['suggestion']['uci'] ?? null;
            $row['cp'] ??= $evaluation['cp'] ?? null;
            $row['mate'] ??= $evaluation['mate'] ?? null;
            $rows[$key] = $row;
        }

        foreach (array_chunk(array_values($rows), self::STAT_BATCH) as $chunk) {
            $values = $params = [];
            foreach ($chunk as $row) {
                $values[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
                array_push(
                    $params,
                    Uuid::v7()->toBinary(),
                    $repertoireId->toBinary(),
                    PositionKey::of($row['fen'])->hash,
                    $row['fen'],
                    $row['uci'],
                    $row['games'],
                    $row['wins'],
                    $row['draws'],
                    $row['losses'],
                    $row['analysed'],
                    $row['errored'],
                    $row['thinkCount'] > 0 ? intdiv($row['thinkSum'], $row['thinkCount']) : null,
                    $row['moveAnalysed'],
                    $row['moveErrors'],
                    $row['best'],
                    $row['cp'],
                    $row['mate'],
                    $now->format('Y-m-d H:i:s'),
                );
            }
            $this->connection->executeStatement(
                'INSERT INTO repertoire_game_stat (id, repertoire_id, fen_hash, fen, uci, games, wins, draws, losses, analysed, errored, think_cs, move_analysed, move_errors, best_uci, eval_cp, eval_mate, created_at) VALUES '.implode(', ', $values),
                $params,
            );
        }
    }
}
