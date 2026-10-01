<?php

declare(strict_types=1);

namespace App\Repository\Repertoire;

use App\Entity\Repertoire\Presentation;
use App\Entity\Training\Run;
use App\Enum\Repertoire\PresentationStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Presentation>
 */
class PresentationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Presentation::class);
    }

    /**
     * The finished presentations of these segments, newest first.
     *
     * @param list<Uuid> $segmentIds
     *
     * @return list<Presentation>
     */
    public function findFinishedOf(array $segmentIds, int $limit): array
    {
        /** @var list<Presentation> */
        return $this->createQueryBuilder('p')
            ->where('IDENTITY(p.segment) IN (:segments)')
            ->andWhere('p.status <> :inProgress')
            ->setParameter('segments', array_map(static fn (Uuid $id): string => $id->toBinary(), $segmentIds), ArrayParameterType::BINARY)
            ->setParameter('inProgress', PresentationStatus::InProgress->value)
            ->orderBy('p.startedAt', 'DESC')
            ->addOrderBy('p.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<Presentation> in the order they were shown
     */
    public function findByRun(Run $run): array
    {
        return $this->findBy(['run' => $run], ['startedAt' => 'ASC', 'id' => 'ASC']);
    }
}
