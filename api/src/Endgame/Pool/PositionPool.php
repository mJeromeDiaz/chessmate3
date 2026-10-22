<?php

declare(strict_types=1);

namespace App\Endgame\Pool;

use App\Chess\Position\FenNormalizer;
use App\Endgame\Lesson\Lesson;
use App\Endgame\Tablebase\Answer;
use App\Enum\Endgame\Goal;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Clock\ClockInterface;
use Random\Randomizer;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The positions served by each lesson (`endgame_position`, docs/ENDGAMES.md, § 4), in the endgame
 * database: added once the tablebase accepted them, picked at random.
 *
 * @phpstan-type PoolPosition array{id: int, fen: string, dtm: int|null}
 */
final readonly class PositionPool
{
    /** Mates shorter than this (plies) teach nothing: not kept. */
    public const MIN_MATE_PLIES = 9;

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.endgame_connection')]
        private Connection $connection,
        private FenNormalizer $normalizer,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Does the tablebase's answer fit the lesson? Its result for the player, a mate long enough to
     * learn something, and, to hold a draw, at least one move that loses it.
     */
    public static function accepts(Lesson $lesson, Answer $answer): bool
    {
        if ($answer->result() !== $lesson->result || $answer->checkmate || $answer->stalemate) {
            return false;
        }
        if (Goal::Mate === $lesson->goal && ($answer->dtm ?? 0) < self::MIN_MATE_PLIES) {
            return false;
        }
        if (Goal::Hold === $lesson->goal) {
            return \count($answer->keepingMoves()) < \count($answer->moves);
        }

        return true;
    }

    public function has(Lesson $lesson, string $fen): bool
    {
        $key = $this->normalizer->key($fen);

        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM endgame_position WHERE lesson_key = ? AND fen_hash = ?',
            [$lesson->key, $key->hash],
            [ParameterType::STRING, ParameterType::BINARY],
        );
    }

    /**
     * @return bool false when it was already there
     */
    public function add(Lesson $lesson, string $fen, Answer $answer, Randomizer $randomizer): bool
    {
        $key = $this->normalizer->key($fen);

        return 1 === (int) $this->connection->executeStatement(
            'INSERT IGNORE INTO endgame_position (lesson_key, fen, fen_hash, dtm, textbook, random_key, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$lesson->key, $key->fen.' 0 1', $key->hash, null === $answer->dtm ? null : abs($answer->dtm), $key->fen === $this->normalizer->normalize($lesson->textbook) ? 1 : 0, $randomizer->getInt(0, 2_147_483_647), $this->clock->now()->format('Y-m-d H:i:s')],
            [ParameterType::STRING, ParameterType::STRING, ParameterType::BINARY, ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::STRING],
        );
    }

    public function count(Lesson $lesson): int
    {
        $count = $this->connection->fetchOne('SELECT COUNT(*) FROM endgame_position WHERE lesson_key = ?', [$lesson->key]);

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * A position of the lesson at random, the textbook one excluded (a variant), null when none.
     *
     * @param list<int> $excluded ids already served
     *
     * @return PoolPosition|null
     */
    public function pick(Lesson $lesson, Randomizer $randomizer, array $excluded = []): ?array
    {
        $from = $randomizer->getInt(0, 2_147_483_647);
        $exclusion = [] === $excluded ? '' : ' AND id NOT IN (?)';
        foreach (['random_key >= ? ORDER BY random_key ASC', 'random_key < ? ORDER BY random_key DESC'] as $order) {
            $row = $this->connection->fetchAssociative(
                'SELECT id, fen, dtm FROM endgame_position WHERE lesson_key = ? AND textbook = 0'.$exclusion.' AND '.$order.' LIMIT 1',
                [$lesson->key, ...([] === $excluded ? [] : [$excluded]), $from],
                [ParameterType::STRING, ...([] === $excluded ? [] : [ArrayParameterType::INTEGER]), ParameterType::INTEGER],
            );
            if (false !== $row) {
                return [
                    'id' => (int) (is_numeric($row['id']) ? $row['id'] : 0),
                    'fen' => \is_string($row['fen']) ? $row['fen'] : '',
                    'dtm' => is_numeric($row['dtm']) ? (int) $row['dtm'] : null,
                ];
            }
        }

        return null;
    }
}
