<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AccountDeletionCode;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AccountDeletionCode>
 */
class AccountDeletionCodeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccountDeletionCode::class);
    }

    public function findOneByUser(User $user): ?AccountDeletionCode
    {
        return $this->findOneBy(['user' => $user]);
    }

    public function save(AccountDeletionCode $code): void
    {
        $this->getEntityManager()->persist($code);
        $this->getEntityManager()->flush();
    }

    public function deleteForUser(User $user): void
    {
        $this->createQueryBuilder('c')
            ->delete()
            ->where('c.user = :user')
            ->setParameter('user', $user->getId(), 'uuid')
            ->getQuery()
            ->execute();
    }

    /**
     * Spends one attempt before the code is compared, atomically: two concurrent guesses cannot
     * both use the last attempt.
     *
     * @return bool false: no attempt left
     */
    public function reserveAttempt(AccountDeletionCode $code, int $max): bool
    {
        /** @var int $affected */
        $affected = $this->createQueryBuilder('c')
            ->update()
            ->set('c.attempts', 'c.attempts + 1')
            ->where('c.id = :id')
            ->andWhere('c.attempts < :max')
            ->setParameter('id', $code->getId(), 'uuid')
            ->setParameter('max', $max)
            ->getQuery()
            ->execute();

        return 1 === $affected;
    }
}
