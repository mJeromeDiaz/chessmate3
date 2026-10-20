<?php

declare(strict_types=1);

namespace App\Repository\Vision;

use App\Entity\Training\Run;
use App\Entity\User;
use App\Entity\Vision\Question;
use App\Enum\Vision\QuestionStatus;
use App\Vision\VisionRules;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * The questions of the vision module, only changed under their run's lock (the run is locked first).
 *
 * @extends ServiceEntityRepository<Question>
 */
class QuestionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Question::class);
    }

    public function findOwned(Uuid $id, User $user): ?Question
    {
        return $this->findOneBy(['id' => $id, 'user' => $user]);
    }

    public function findPendingOfRun(Run $run): ?Question
    {
        return $this->findOneBy(['run' => $run, 'status' => QuestionStatus::Pending]);
    }

    /**
     * @return list<Question> in the order asked
     */
    public function findResolvedOfRun(Run $run): array
    {
        /** @var list<Question> */
        return $this->createQueryBuilder('q')
            ->andWhere('q.run = :run')
            ->andWhere('q.status <> :pending')
            ->setParameter('run', $run->getId(), 'uuid')
            ->setParameter('pending', QuestionStatus::Pending->value)
            ->orderBy('q.startedAt', 'ASC')
            ->addOrderBy('q.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The run's questions by verdict and their time (each one capped).
     *
     * @return array{right: int, wrong: int, activeMs: int}
     */
    public function statsOfRun(Run $run): array
    {
        $stats = ['right' => 0, 'wrong' => 0, 'activeMs' => 0];
        foreach ($this->findResolvedOfRun($run) as $question) {
            ++$stats[QuestionStatus::Right === $question->getStatus() ? 'right' : 'wrong'];
            $stats['activeMs'] += min(VisionRules::ACTIVE_TIME_CAP_MS, $question->getDurationMs() ?? 0);
        }

        return $stats;
    }

    /**
     * The puzzles of a run so far, pending one included (none is served twice in a run).
     *
     * @return list<int>
     */
    public function puzzleIdsOfRun(Run $run): array
    {
        return array_map(
            static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0,
            $this->getEntityManager()->getConnection()->fetchFirstColumn(
                'SELECT puzzle_id FROM vision_question WHERE run_id = ? AND puzzle_id IS NOT NULL',
                [$run->getId()->toBinary()],
                [ParameterType::BINARY],
            ),
        );
    }

    /**
     * Among $puzzleIds, those the user already met in this module.
     *
     * @param list<int> $puzzleIds
     *
     * @return list<int>
     */
    public function metAmong(User $user, array $puzzleIds): array
    {
        if ([] === $puzzleIds) {
            return [];
        }

        return array_map(
            static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0,
            $this->getEntityManager()->getConnection()->fetchFirstColumn(
                'SELECT DISTINCT puzzle_id FROM vision_question WHERE user_id = ? AND puzzle_id IN (?)',
                [$user->getId()->toBinary(), $puzzleIds],
                [ParameterType::BINARY, ArrayParameterType::INTEGER],
            ),
        );
    }
}
