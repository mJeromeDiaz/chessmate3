<?php

declare(strict_types=1);

namespace App\Repository\EarlyAccess;

use App\Entity\EarlyAccess\AccessRequest;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<AccessRequest>
 */
class AccessRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccessRequest::class);
    }

    /**
     * Records a request, once per address: a second one for the same address changes nothing
     * (INSERT IGNORE on the unique email, so two simultaneous requests can't fail either).
     */
    public function add(string $email, \DateTimeImmutable $now): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT IGNORE INTO early_access_request (id, email, created_at) VALUES (?, ?, ?)',
            [Uuid::v7(), $email, $now],
            ['uuid', Types::STRING, Types::DATETIME_IMMUTABLE],
        );
    }

    /**
     * Claims a request for an invitation: true for the one caller that marks it invited first,
     * false if it already was (two admins clicking at once create one invitation).
     * Raw SQL: reload the entity before reading its invitedAt.
     */
    public function claim(Uuid $id, \DateTimeImmutable $now): bool
    {
        return 1 === $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE early_access_request SET invited_at = ? WHERE id = ? AND invited_at IS NULL',
            [$now, $id],
            [Types::DATETIME_IMMUTABLE, 'uuid'],
        );
    }

    /**
     * The admin list, in order of arrival.
     *
     * @param bool|null $invited null: all; true: invited; false: waiting
     */
    public function createListQueryBuilder(?bool $invited = null): QueryBuilder
    {
        $qb = $this->createQueryBuilder('r')
            ->leftJoin('r.invitation', 'i')->addSelect('i')
            ->orderBy('r.id', 'ASC');
        if (true === $invited) {
            $qb->andWhere('r.invitedAt IS NOT NULL');
        } elseif (false === $invited) {
            $qb->andWhere('r.invitedAt IS NULL');
        }

        return $qb;
    }

    /**
     * Which of these addresses already belong to an account.
     *
     * @param list<string> $emails
     *
     * @return array<string, true> keyed by lowercased address
     */
    public function findEmailsWithAccount(array $emails): array
    {
        if ([] === $emails) {
            return [];
        }
        /** @var list<string> $taken */
        $taken = $this->getEntityManager()->createQueryBuilder()
            ->select('u.email')
            ->from(User::class, 'u')
            ->where('u.email IN (:emails)')
            ->setParameter('emails', $emails)
            ->getQuery()
            ->getSingleColumnResult();

        return array_fill_keys(array_map(mb_strtolower(...), $taken), true);
    }
}
