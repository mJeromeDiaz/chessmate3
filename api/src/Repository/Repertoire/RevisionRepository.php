<?php

declare(strict_types=1);

namespace App\Repository\Repertoire;

use App\Entity\Repertoire\Repertoire;
use App\Entity\Repertoire\Revision;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Revision>
 */
class RevisionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Revision::class);
    }

    public function findLatest(Repertoire $repertoire): ?Revision
    {
        return $this->findOneBy(['repertoire' => $repertoire], ['version' => 'DESC']);
    }

    public function countOf(Repertoire $repertoire): int
    {
        return $this->count(['repertoire' => $repertoire]);
    }

    /**
     * Keeps the $keep latest revisions of the repertoire.
     */
    public function trim(Repertoire $repertoire, int $keep): void
    {
        $connection = $this->getEntityManager()->getConnection();
        $threshold = $connection->fetchOne(
            'SELECT version FROM repertoire_revision WHERE repertoire_id = ? ORDER BY version DESC LIMIT 1 OFFSET ?',
            [$repertoire->getId()->toBinary(), $keep],
            [ParameterType::BINARY, ParameterType::INTEGER],
        );
        if (false !== $threshold) {
            $connection->executeStatement(
                'DELETE FROM repertoire_revision WHERE repertoire_id = ? AND version <= ?',
                [$repertoire->getId()->toBinary(), $threshold],
                [ParameterType::BINARY, ParameterType::INTEGER],
            );
        }
    }
}
