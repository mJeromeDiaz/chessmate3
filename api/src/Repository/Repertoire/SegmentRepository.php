<?php

declare(strict_types=1);

namespace App\Repository\Repertoire;

use App\Entity\Repertoire\Repertoire;
use App\Entity\Repertoire\Segment;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Segment>
 */
class SegmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Segment::class);
    }

    /**
     * Every segment of the repertoire, archived ones included, oldest first.
     *
     * @return list<Segment>
     */
    public function findAllOf(Repertoire $repertoire): array
    {
        return $this->findBy(['repertoire' => $repertoire], ['id' => 'ASC']);
    }

    /**
     * @return list<Segment> active segments, oldest first
     */
    public function findActiveOf(Repertoire $repertoire): array
    {
        return $this->findBy(['repertoire' => $repertoire, 'archivedAt' => null], ['id' => 'ASC']);
    }

    /**
     * Active segments with at least one user's move (presented in tests), per repertoire of the user.
     *
     * @return array<string, int> repertoire id (RFC 4122) => count
     */
    public function countPresentableByRepertoire(User $user): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT s.repertoire_id, COUNT(*) AS n FROM repertoire_segment s JOIN repertoire r ON r.id = s.repertoire_id
             WHERE r.user_id = ? AND s.archived_at IS NULL AND s.user_move_count > 0 GROUP BY s.repertoire_id',
            [$user->getId()->toBinary()],
            [ParameterType::BINARY],
        );
        $counts = [];
        foreach ($rows as $row) {
            if (\is_string($row['repertoire_id']) && is_numeric($row['n'])) {
                $counts[Uuid::fromBinary($row['repertoire_id'])->toRfc4122()] = (int) $row['n'];
            }
        }

        return $counts;
    }

    public function findInRepertoire(Uuid $id, Repertoire $repertoire): ?Segment
    {
        return $this->findOneBy(['id' => $id, 'repertoire' => $repertoire]);
    }
}
