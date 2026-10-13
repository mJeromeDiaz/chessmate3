<?php

declare(strict_types=1);

namespace App\Repository\PuzzleStreak;

use App\Entity\PuzzleStreak\Streak;
use App\Entity\Training\Run;
use App\Entity\User;
use App\Enum\PuzzleStreak\Ramp;
use App\Enum\PuzzleStreak\StreakStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Puzzle streaks are only changed under their run's lock (the run is locked first).
 *
 * @extends ServiceEntityRepository<Streak>
 */
class StreakRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Streak::class);
    }

    public function findActiveOfRun(Run $run): ?Streak
    {
        return $this->findOneBy(['run' => $run, 'status' => StreakStatus::Active]);
    }

    /**
     * @return list<Streak> in the order played
     */
    public function findOfRun(Run $run): array
    {
        /** @var list<Streak> */
        return $this->createQueryBuilder('s')
            ->andWhere('s.run = :run')
            ->setParameter('run', $run->getId(), 'uuid')
            ->orderBy('s.startedAt', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The user's longest streak over of this ramp and topic, $streak itself left out (0: none).
     */
    public function bestBefore(Streak $streak): int
    {
        $best = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT MAX(length) FROM puzzle_streak WHERE user_id = ? AND ramp = ? AND topic = ? AND status <> ? AND id <> ?',
            [$streak->getUser()->getId()->toBinary(), $streak->getRamp()->value, $streak->getTopic(), StreakStatus::Active->value, $streak->getId()->toBinary()],
            [ParameterType::BINARY, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::BINARY],
        );

        return is_numeric($best) ? (int) $best : 0;
    }

    /**
     * The user's longest streak of this ramp and topic in other runs (0: none).
     */
    public function bestOutside(Run $run, Ramp $ramp, string $topic): int
    {
        $best = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT MAX(length) FROM puzzle_streak WHERE user_id = ? AND ramp = ? AND topic = ? AND status <> ? AND run_id <> ?',
            [$run->getUser()->getId()->toBinary(), $ramp->value, $topic, StreakStatus::Active->value, $run->getId()->toBinary()],
            [ParameterType::BINARY, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::BINARY],
        );

        return is_numeric($best) ? (int) $best : 0;
    }

    /**
     * The user's streaks over, by ramp and topic: the longest, how many, and when the longest
     * ended (the first one to reach it).
     *
     * @return list<array{ramp: string, topic: string, best: int, streaks: int, at: string|null}>
     */
    public function recordsOf(User $user): array
    {
        // One row per length reached: few per ramp and topic.
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT ramp, topic, length, COUNT(*) AS n, MIN(ended_at) AS at FROM puzzle_streak
              WHERE user_id = ? AND status <> ? GROUP BY ramp, topic, length',
            [$user->getId()->toBinary(), StreakStatus::Active->value],
            [ParameterType::BINARY],
        );
        /** @var array<string, array{ramp: string, topic: string, best: int, streaks: int, at: string|null}> $records */
        $records = [];
        foreach ($rows as $row) {
            $ramp = \is_string($row['ramp']) ? $row['ramp'] : '';
            $topic = \is_string($row['topic']) ? $row['topic'] : '';
            $length = is_numeric($row['length']) ? (int) $row['length'] : 0;
            $record = $records[$ramp.' '.$topic] ?? ['ramp' => $ramp, 'topic' => $topic, 'best' => -1, 'streaks' => 0, 'at' => null];
            $record['streaks'] += is_numeric($row['n']) ? (int) $row['n'] : 0;
            if ($length > $record['best']) {
                $record['best'] = $length;
                $record['at'] = \is_string($row['at']) ? (new \DateTimeImmutable($row['at'], new \DateTimeZone('UTC')))->format(\DATE_ATOM) : null;
            }
            $records[$ramp.' '.$topic] = $record;
        }
        $records = array_values($records);
        usort($records, static fn (array $a, array $b): int => [$b['best'], $a['ramp'], $a['topic']] <=> [$a['best'], $b['ramp'], $b['topic']]);

        return $records;
    }
}
