<?php

declare(strict_types=1);

namespace App\Repository\EarlyAccess;

use App\Entity\EarlyAccess\InvitationKey;
use App\Entity\EarlyAccess\InvitationLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InvitationLog>
 */
class InvitationLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InvitationLog::class);
    }

    /**
     * @return list<InvitationLog> oldest first
     */
    public function findForInvitation(InvitationKey $invitation): array
    {
        /** @var list<InvitationLog> */
        return $this->createQueryBuilder('l')
            ->andWhere('l.invitation = :invitation')->setParameter('invitation', $invitation->getId(), 'uuid')
            ->orderBy('l.createdAt', 'ASC')
            ->addOrderBy('l.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
