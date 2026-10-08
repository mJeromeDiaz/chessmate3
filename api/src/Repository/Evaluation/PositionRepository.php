<?php

declare(strict_types=1);

namespace App\Repository\Evaluation;

use App\Entity\Evaluation\Position;
use App\Entity\Training\Run;
use App\Enum\Evaluation\PositionTag;
use App\Enum\Repertoire\Color;
use App\Evaluation\EvaluationRules;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Position>
 */
class PositionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Position::class);
    }

    /**
     * The admin list, newest first: active or inactive only when asked, a tag.
     */
    public function createListQueryBuilder(?bool $active, ?PositionTag $tag): QueryBuilder
    {
        $qb = $this->createQueryBuilder('p')->orderBy('p.createdAt', 'DESC')->addOrderBy('p.id', 'DESC');
        if (null !== $active) {
            $qb->andWhere('p.active = :active')->setParameter('active', $active);
        }
        if (null !== $tag) {
            $qb->andWhere('p.tag = :tag')->setParameter('tag', $tag->value);
        }

        return $qb;
    }

    /**
     * How many times each position was answered.
     *
     * @param list<Position> $positions
     *
     * @return array<string, int> by position id (RFC 4122)
     */
    public function playCounts(array $positions): array
    {
        if ([] === $positions) {
            return [];
        }
        $counts = [];
        foreach ($this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT position_id, COUNT(*) AS n FROM evaluation_attempt WHERE position_id IN (?) GROUP BY position_id',
            [array_map(static fn (Position $position): string => $position->getId()->toBinary(), $positions)],
            [ArrayParameterType::BINARY],
        ) as $row) {
            if (\is_string($row['position_id']) && is_numeric($row['n'])) {
                $counts[Uuid::fromBinary($row['position_id'])->toRfc4122()] = (int) $row['n'];
            }
        }

        return $counts;
    }

    /**
     * The next position of a run (docs/EVALUATION.md): never one already in the run; within the
     * Elo window first (then twice as far, then any), never seen by the player first, then the one
     * seen longest ago; at random among equals. Null when none is left. (The rating column is
     * unsigned: MySQL refuses a negative difference unless it is cast.)
     *
     * @param list<Color> $turns the sides to move asked
     */
    public function pick(Run $run, array $turns, int $elo): ?Position
    {
        $id = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT p.id FROM evaluation_position p
               LEFT JOIN (SELECT position_id, MAX(served_at) AS last_served FROM evaluation_attempt WHERE user_id = :user GROUP BY position_id) seen
                 ON seen.position_id = p.id
              WHERE p.active = 1 AND p.turn IN (:turns)
                AND p.id NOT IN (SELECT position_id FROM evaluation_attempt WHERE run_id = :run)
              ORDER BY CASE WHEN ABS(CAST(p.rating AS SIGNED) - :elo) <= :window THEN 0 WHEN ABS(CAST(p.rating AS SIGNED) - :elo) <= :wide THEN 1 ELSE 2 END,
                       seen.last_served IS NOT NULL, seen.last_served, RAND()
              LIMIT 1',
            [
                'user' => $run->getUser()->getId()->toBinary(),
                'turns' => array_map(static fn (Color $turn): string => $turn->value, $turns),
                'run' => $run->getId()->toBinary(),
                'elo' => $elo,
                'window' => EvaluationRules::ELO_WINDOW,
                'wide' => 2 * EvaluationRules::ELO_WINDOW,
            ],
            [
                'user' => ParameterType::BINARY,
                'turns' => ArrayParameterType::STRING,
                'run' => ParameterType::BINARY,
                'elo' => ParameterType::INTEGER,
                'window' => ParameterType::INTEGER,
                'wide' => ParameterType::INTEGER,
            ],
        );

        return \is_string($id) ? $this->find(Uuid::fromBinary($id)) : null;
    }

    /**
     * Active positions by side to move.
     *
     * @return array{white: int, black: int}
     */
    public function activeCounts(): array
    {
        $counts = ['white' => 0, 'black' => 0];
        foreach ($this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT turn, COUNT(*) AS n FROM evaluation_position WHERE active = 1 GROUP BY turn',
        ) as $row) {
            if (\is_string($row['turn']) && isset($counts[$row['turn']]) && is_numeric($row['n'])) {
                $counts[$row['turn']] = (int) $row['n'];
            }
        }

        return $counts;
    }
}
