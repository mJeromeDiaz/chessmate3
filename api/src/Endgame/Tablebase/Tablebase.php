<?php

declare(strict_types=1);

namespace App\Endgame\Tablebase;

use App\Chess\InvalidPositionException;
use App\Chess\Position\FenNormalizer;
use App\Chess\Position\PositionKey;
use App\Repertoire\Lichess\LichessGateway;
use App\Repertoire\Lichess\LichessUnavailableException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The Lichess endgame tablebase (tablebase.lichess.ovh, 7 pieces at most, no token) for the
 * endgames module (docs/ENDGAMES.md, § 2): each position is asked once, through
 * {@see LichessGateway}, and its answer kept in `endgame_tablebase` (the endgame database).
 *
 * Positions are keyed by their normalized FEN, without the move counters: Lichess is asked with a
 * fresh 50-move counter. Elementary endgames end far before it matters.
 */
final readonly class Tablebase
{
    public const MAX_PIECES = 7;

    public function __construct(
        private HttpClientInterface $lichessTablebaseClient,
        private LichessGateway $gateway,
        #[Autowire(service: 'doctrine.dbal.endgame_connection')]
        private Connection $connection,
        private FenNormalizer $normalizer,
        private ClockInterface $clock,
    ) {
    }

    /**
     * The answer for a position, null when the tablebase does not cover it (more than 7 pieces,
     * castling rights) or does not know it.
     *
     * @throws InvalidPositionException
     * @throws LichessUnavailableException when the position is not kept and Lichess does not answer
     */
    public function probe(string $fen): ?Answer
    {
        $key = $this->normalizer->key($fen);
        if (!self::covers($key->fen)) {
            return null;
        }
        $kept = $this->kept($key);
        if (null !== $kept) {
            return $kept;
        }

        $response = $this->gateway->get($this->lichessTablebaseClient, '/standard', ['fen' => $key->fen.' 0 1'], null);
        if (200 !== $response['status'] || null === $response['body']) {
            return null;
        }
        $answer = Answer::fromLichess($response['body']);
        $this->connection->executeStatement(
            'INSERT INTO endgame_tablebase (fen_hash, fen, answer, fetched_at, read_on) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE answer = VALUES(answer), fetched_at = VALUES(fetched_at), read_on = VALUES(read_on)',
            [$key->hash, $key->fen, json_encode($answer->toStored(), \JSON_THROW_ON_ERROR), $this->clock->now()->format('Y-m-d H:i:s'), $this->clock->now()->format('Y-m-d')],
            [ParameterType::BINARY, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING],
        );

        return $answer;
    }

    /**
     * The answer already kept, without asking Lichess (null: not kept, or not covered).
     *
     * @throws InvalidPositionException
     */
    public function lookup(string $fen): ?Answer
    {
        $key = $this->normalizer->key($fen);

        return self::covers($key->fen) ? $this->kept($key) : null;
    }

    /** The tablebase knows positions of 7 pieces at most, without castling rights. */
    public static function covers(string $normalizedFen): bool
    {
        [$placement, , $castling] = explode(' ', $normalizedFen);

        return '-' === $castling && preg_match_all('/[pnbrqk]/i', $placement) <= self::MAX_PIECES;
    }

    private function kept(PositionKey $key): ?Answer
    {
        $row = $this->connection->fetchAssociative(
            'SELECT answer, read_on FROM endgame_tablebase WHERE fen_hash = ? AND fen = ?',
            [$key->hash, $key->fen],
            [ParameterType::BINARY, ParameterType::STRING],
        );
        if (false === $row || !\is_string($row['answer'])) {
            return null;
        }
        $today = $this->clock->now()->format('Y-m-d');
        if ($row['read_on'] !== $today) {
            $this->connection->executeStatement('UPDATE endgame_tablebase SET read_on = ? WHERE fen_hash = ?', [$today, $key->hash], [ParameterType::STRING, ParameterType::BINARY]);
        }
        $stored = json_decode($row['answer'], true);

        return Answer::fromStored(\is_array($stored) ? $stored : []);
    }

    /**
     * Deletes the answers unread since before the given day, a batch at a time.
     *
     * @return int rows deleted
     */
    public function prune(\DateTimeImmutable $unreadSince, int $batch = 5000): int
    {
        $deleted = 0;
        do {
            $count = (int) $this->connection->executeStatement(
                'DELETE FROM endgame_tablebase WHERE read_on < ? LIMIT ?',
                [$unreadSince->format('Y-m-d'), $batch],
                [ParameterType::STRING, ParameterType::INTEGER],
            );
            $deleted += $count;
        } while ($count === $batch);

        return $deleted;
    }
}
