<?php

declare(strict_types=1);

namespace App\Repertoire\Auto;

use App\Chess\Position\PositionKey;
use App\Entity\User;
use App\Repertoire\Lichess\ExplorerClient;
use App\Repertoire\Lichess\ExplorerQuery;
use App\Repertoire\Lichess\LichessUnavailableException;
use App\Repertoire\Limits;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The explorer's replies in a position for an analysis (docs/REPERTOIRE.md, "Repertoire from my
 * games"): repertoire_opening_stats first (the precomputed first moves), else the explorer through
 * its MySQL cache. A row older than {@see Limits::$openingStatsTtlDays} is refreshed on demand,
 * never served frozen.
 *
 * @phpstan-import-type Replies from ReplyRule
 */
final readonly class OpeningStatsReader implements ReplySource
{
    public function __construct(
        private Connection $connection,
        private ExplorerClient $explorer,
        private Limits $limits,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param User|null $user    whose Lichess token is tried first (null: the application's)
     * @param bool|null $fetched set to true when Lichess was really asked (what the call cap counts)
     *
     * @param-out bool $fetched
     *
     * @return Replies
     *
     * @throws LichessUnavailableException
     */
    public function replies(?User $user, string $fen, ?bool &$fetched = null, string $preset = Limits::AUTO_EXPLORER_PRESET): array
    {
        $fetched = false;
        $stored = $this->stored($fen);
        if (null !== $stored && !$stored['stale']) {
            return $stored['replies'];
        }
        $replies = ReplyRule::compact($this->explorer->explore($user, self::query($fen, $preset), $fetched));
        if (null !== $stored) {
            $this->store($fen, $replies);
        }

        return $replies;
    }

    /**
     * A row of the table, null when the position has none.
     *
     * @return array{replies: Replies, stale: bool}|null
     */
    public function stored(string $fen): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT fen, total, moves, fetched_at FROM repertoire_opening_stats WHERE fen_hash = ?',
            [PositionKey::of($fen)->hash],
        );
        if (false === $row || $row['fen'] !== $fen || !\is_string($row['moves']) || !is_numeric($row['total']) || !\is_string($row['fetched_at'])) {
            return null;
        }
        $replies = ReplyRule::fromJson(['total' => (int) $row['total'], 'moves' => json_decode($row['moves'], true)]);
        if (null === $replies) {
            return null;
        }
        $fetchedAt = new \DateTimeImmutable($row['fetched_at'], new \DateTimeZone('UTC'));
        $stale = $fetchedAt <= $this->clock->now()->modify(\sprintf('-%d days', $this->limits->openingStatsTtlDays));

        return ['replies' => $replies, 'stale' => $stale];
    }

    /**
     * Writes (or refreshes) a row.
     *
     * @param Replies $replies
     */
    public function store(string $fen, array $replies): void
    {
        $this->connection->executeStatement(
            'INSERT INTO repertoire_opening_stats (id, fen_hash, fen, total, moves, fetched_at) VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE total = VALUES(total), moves = VALUES(moves), fetched_at = VALUES(fetched_at)',
            [
                Uuid::v7()->toBinary(),
                PositionKey::of($fen)->hash,
                $fen,
                $replies['total'],
                json_encode($replies['moves'], \JSON_THROW_ON_ERROR),
                $this->clock->now()->format('Y-m-d H:i:s'),
            ],
        );
    }

    public static function query(string $fen, string $preset = Limits::AUTO_EXPLORER_PRESET): ExplorerQuery
    {
        $filters = Limits::EXPLORER_PRESETS[$preset] ?? Limits::EXPLORER_PRESETS[Limits::AUTO_EXPLORER_PRESET];

        return new ExplorerQuery(ExplorerQuery::LICHESS, $fen, $filters['speeds'], $filters['ratings']);
    }
}
